package com.ivac.booking.service.captcha;

import com.google.gson.Gson;
import com.google.gson.JsonObject;
import com.ivac.booking.config.ProtocolConstantsRefresher;
import com.ivac.booking.model.domain.CaptchaToken;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;
import java.util.HashMap;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.Executors;


public class PortalCaptchaClient {

    private static final Logger log = LoggerFactory.getLogger(PortalCaptchaClient.class);

    private static final Gson GSON = new Gson();

    // Poll spacing widens with age instead of being one flat interval. A request that misses
    // the portal pool is raced across several providers, so the fast outcomes land in the
    // first couple of seconds and are worth checking for often; past that the request is
    // waiting on a slow vendor and frequent polling only costs the portal FPM workers during
    // the window. Pool hits never reach this loop at all - they are answered by the POST.
    private static final long POLL_FAST_MS = 100;
    private static final long POLL_FAST_UNTIL_MS = 2_000;
    private static final long POLL_MEDIUM_MS = 300;
    private static final long POLL_MEDIUM_UNTIL_MS = 6_000;
    private static final long POLL_SLOW_MS = 750;

    // Must be > portal's TASK_TIMEOUT_SECONDS (60s) so the bot always gets a definitive
    // ready/failed response rather than timing out while the portal is still solving.
    private static final long POLL_TIMEOUT_MS = 65_000;

    // Upper bound on how long a ready token waits for an in-flight constants refresh. The portal
    // enables providers a beat after publishing new constants, so this is normally already satisfied.
    private static final long REFRESH_AWAIT_MS = 3_000;

    private final String baseUrl;
    private final String apiToken;
    private final HttpClient http;

    public PortalCaptchaClient(String baseUrl) {
        this(baseUrl, resolveApiToken());
    }

    public PortalCaptchaClient(String baseUrl, String apiToken) {
        this.baseUrl = baseUrl.replaceAll("/+$", "");
        this.apiToken = apiToken == null ? "" : apiToken.trim();
        this.http = HttpClient.newBuilder()
                .connectTimeout(Duration.ofSeconds(30))
                .version(HttpClient.Version.HTTP_2)
                .followRedirects(HttpClient.Redirect.NEVER)
                .executor(Executors.newVirtualThreadPerTaskExecutor())
                .build();
    }

    /**
     * Token for the portal's machine-facing captcha API, read from the environment so it is
     * never committed alongside the source. A blank token is left off the request entirely:
     * the portal answers 503 rather than serving an anonymous caller, which is the intended
     * fail-closed behaviour, and the resulting error names the missing configuration.
     */
    private static String resolveApiToken() {
        String token = System.getenv("CAPTCHA_API_TOKEN");

        if (token == null || token.isBlank()) {
            token = System.getProperty("captcha.api.token");
        }

        return token == null ? "" : token;
    }

    private HttpRequest.Builder withAuth(HttpRequest.Builder builder) {
        return apiToken.isEmpty() ? builder : builder.header("Authorization", "Bearer " + apiToken);
    }

    public CompletableFuture<CaptchaToken> requestCaptcha(String type, String phone) {
        return CompletableFuture.supplyAsync(() -> {
            try {
                JsonObject submitted = submitRequest(phone, type);

                // The portal serves a pooled token in the POST itself when the type is named
                // up front, which is the common case. Taking it here saves a whole round trip
                // plus a poll interval on the path that matters most.
                CaptchaToken immediate = readToken(submitted, type, phone);

                if (immediate != null) {
                    return immediate;
                }

                return pollUntilReady(submitted.get("request_id").getAsString(), type, phone);
            } catch (IOException | InterruptedException e) {
                if (e instanceof InterruptedException) {
                    Thread.currentThread().interrupt();
                }

                throw new RuntimeException("Captcha request failed: " + e.getMessage(), e);
            }
        });
    }

    private JsonObject submitRequest(String phone, String type) throws IOException, InterruptedException {
        Map<String, String> payload = new HashMap<>();

        if (phone != null && !phone.isBlank()) {
            payload.put("phone", phone);
        }

        // Naming the type here lets the portal encrypt and hand back a pooled token in this
        // response. Omitting it falls back to the older claim-then-GET exchange.
        if (type != null && !type.isBlank()) {
            payload.put("type", type);
        }

        String body = GSON.toJson(payload);
        HttpRequest request = withAuth(HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/api/captcha/request"))
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .timeout(Duration.ofSeconds(15)))
                .build();

        HttpResponse<String> response = http.send(request, HttpResponse.BodyHandlers.ofString());

        if (response.statusCode() == 429) {
            throw new IOException("Daily captcha limit reached for " + phone);
        }

        if (response.statusCode() != 201 && response.statusCode() != 200) {
            throw new IOException("Portal captcha POST failed: HTTP " + response.statusCode());
        }

        JsonObject json = GSON.fromJson(response.body(), JsonObject.class);
        log.info("[Captcha] Request submitted: {} (phone={})", json.get("request_id").getAsString(), phone);

        return json;
    }

    private CaptchaToken pollUntilReady(String requestId, String type, String phone) throws IOException, InterruptedException {
        long startedAt = System.currentTimeMillis();
        long deadline = startedAt + POLL_TIMEOUT_MS;
        // type passed as query param — portal applies encryption only at consumption time
        String url = baseUrl + "/api/captcha/request/" + requestId + "?type=" + type;

        while (System.currentTimeMillis() < deadline) {
            HttpRequest request = withAuth(HttpRequest.newBuilder()
                    .uri(URI.create(url))
                    .GET()
                    .timeout(Duration.ofSeconds(10)))
                    .build();

            HttpResponse<String> response = http.send(request, HttpResponse.BodyHandlers.ofString());

            if (response.statusCode() == 404) {
                // The row is gone: consumed, expired or purged. No amount of polling brings it
                // back, so surface it now instead of spending the whole budget on a dead id.
                throw new IOException("Captcha request no longer exists: " + requestId);
            }

            if (response.statusCode() != 200) {
                log.warn("[Captcha] Poll returned HTTP {} for {}", response.statusCode(), requestId);
            } else {
                CaptchaToken token = readToken(GSON.fromJson(response.body(), JsonObject.class), type, phone);

                if (token != null) {
                    return token;
                }
            }

            Thread.sleep(pollDelayMs(System.currentTimeMillis() - startedAt));
        }

        throw new IOException("Captcha poll timed out after " + (POLL_TIMEOUT_MS / 1000) + "s for " + requestId);
    }

    /**
     * Read a portal response that may or may not carry a token.
     * Returns null while the request is still pending, and throws once it has failed.
     */
    private CaptchaToken readToken(JsonObject json, String type, String phone) throws IOException {
        // The portal stamps every response with the version of its bundle-derived constants.
        // A mid-window extraction bumps it, so feeding it to the refresher here adopts a rotation
        // while this very request is still parked at the gate.
        if (json.has("config_version") && !json.get("config_version").isJsonNull()) {
            ProtocolConstantsRefresher.getInstance().onVersionSeen(json.get("config_version").getAsString());
        }

        if (!json.has("status") || json.get("status").isJsonNull()) {
            return null;
        }

        String status = json.get("status").getAsString();

        switch (status) {
            case "ready" -> {
                // A token is only issued once the portal has enabled providers, which happens after a
                // clean extraction — so wait out any refresh it just triggered rather than signing in
                // against a path the portal has already replaced.
                ProtocolConstantsRefresher.getInstance().awaitPending(REFRESH_AWAIT_MS);

                String token = json.get("token").getAsString();
                long solvedAtMs = json.has("solved_at_ms") && !json.get("solved_at_ms").isJsonNull()
                        ? json.get("solved_at_ms").getAsLong()
                        : System.currentTimeMillis();
                log.info("[Captcha] Resolved (type={}, phone={})", type, phone);
                return new CaptchaToken(token, solvedAtMs);
            }
            case "failed" -> {
                String error = json.has("error") ? json.get("error").getAsString() : "unknown";
                throw new IOException("Captcha solve failed: " + error);
            }
            default -> {
                return null;
            }
        }
    }

    private long pollDelayMs(long elapsedMs) {
        if (elapsedMs < POLL_FAST_UNTIL_MS) {
            return POLL_FAST_MS;
        }

        return elapsedMs < POLL_MEDIUM_UNTIL_MS ? POLL_MEDIUM_MS : POLL_SLOW_MS;
    }
}
