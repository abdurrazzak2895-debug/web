<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentSlot;
use App\Models\Setting;
use App\Services\BotControl\ProcessBotController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BotController extends Controller
{
    public function __construct(
        protected ProcessBotController $bot,
    ) {}

    /**
     * GET /api/bot/status — check if the bot is running.
     */
    public function status(): JsonResponse
    {
        return response()->json($this->bot->status());
    }

    /**
     * GET /api/bot/commands — manual shell commands (list/kill processes, clear PID files).
     */
    public function commands(): JsonResponse
    {
        return response()->json($this->bot->getManualCommands());
    }

    /**
     * GET /api/bot/setup — validate JAR, CWD, Java.
     */
    public function setup(): JsonResponse
    {
        return response()->json($this->bot->validateSetup());
    }

    /**
     * GET /api/bot/compiled — check if bot classes are compiled.
     */
    public function compiled(): JsonResponse
    {
        return response()->json(['compiled' => $this->bot->isCompiled()]);
    }

    /**
     * POST /api/bot/compile — compile the bot project.
     */
    public function compile(): JsonResponse
    {
        $result = $this->bot->compile();
        $this->audit('compile', $result['success'], $result['message']);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /api/bot/package — build the distributable fat JAR for VPS workers.
     */
    public function package(): JsonResponse
    {
        $result = $this->bot->package();
        $this->audit('package', $result['success'], $result['message']);

        if ($result['success']) {
            $version = $this->readBotVersionFromSource();
            if ($version) {
                Setting::instance()->update(['latest_jar_version' => $version]);
                $result['bot_version'] = $version;
            }
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * GET /api/bot/jar — download the fat JAR.
     * Authenticated via Bearer token matching any agent slot api_key.
     */
    public function downloadJar(Request $request): BinaryFileResponse|JsonResponse
    {
        $apiKey = $request->bearerToken();
        if (! $apiKey || ! AgentSlot::where('api_key', $apiKey)->exists()) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        if (! $this->bot->jarExists()) {
            return response()->json(['error' => 'JAR not built yet. Run POST /api/bot/package first.'], 404);
        }

        return response()->download($this->bot->getJarPath(), 'ivac-booking.jar', [
            'Content-Type' => 'application/java-archive',
        ]);
    }

    /**
     * GET /api/bot/jar-status — check whether the distributable JAR exists.
     */
    public function jarStatus(): JsonResponse
    {
        return response()->json([
            'exists' => $this->bot->jarExists(),
            'path' => basename($this->bot->getJarPath()),
        ]);
    }

    /**
     * GET /api/bot/logs — last N lines of bot log.
     */
    public function logs(Request $request): JsonResponse
    {
        $lines = (int) $request->query('lines', 100);
        $lines = min(max($lines, 1), 500);

        return response()->json($this->bot->getLogs($lines));
    }

    /**
     * GET /api/bot/logs/download — download the full bot log file.
     */
    public function downloadLogs(): StreamedResponse|JsonResponse
    {
        $path = $this->bot->getLogFilePath();
        if (! is_file($path)) {
            return response()->json(['message' => 'Log file not found.'], 404);
        }

        $filename = basename($path) ?: 'bot.log';

        return response()->streamDownload(function () use ($path) {
            $stream = fopen($path, 'r');
            if ($stream) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * POST /api/bot/logs/clear — truncate the bot log file.
     */
    public function clearLogs(): JsonResponse
    {
        $result = $this->bot->clearLogs();
        $this->audit('clear-logs', $result['success'], $result['message'] ?? '');

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /api/bot/start — start the bot process.
     * Config is fetched from this app's APP_URL so the bot uses this instance's data.
     */
    public function start(): JsonResponse
    {
        $gmail = auth()->user()?->email ?? '';

        // A signed link, not `?gmail=`: /api/config carries account sign-in material and
        // refuses to answer without a credential now. The JAR fetches whatever URL it is
        // handed, so it needs no change — this link is its credential, and it expires.
        $configUrl = URL::temporarySignedRoute(
            'bot.config',
            now()->addHours((int) config('captcha.bot_config_url_ttl_hours', 168)),
            ['gmail' => $gmail],
        );

        $result = $this->bot->start($configUrl);
        $this->audit('start', $result['success'], $result['message'] ?? '');

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /api/bot/stop — graceful stop (SIGTERM).
     */
    public function stop(): JsonResponse
    {
        $result = $this->bot->stop();
        $this->audit('stop', $result['success'], $result['message'] ?? '');

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /api/bot/kill — force kill all matching processes (SIGKILL).
     */
    public function kill(): JsonResponse
    {
        try {
            $result = $this->bot->kill();
            $this->audit('kill', $result['success'], $result['message'] ?? '');

            return response()->json($result);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Kill failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/bot/release-threads — graceful shutdown (same as stop).
     */
    public function releaseThreads(): JsonResponse
    {
        $result = $this->bot->releaseThreads();
        $this->audit('release-threads', $result['success'], $result['message'] ?? '');

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * GET /api/bot/processes — list all running Java processes.
     */
    public function processes(): JsonResponse
    {
        return response()->json($this->bot->listProcesses());
    }

    /**
     * POST /api/bot/processes/{pid}/kill — kill a specific process by PID.
     */
    public function killProcess(int $pid): JsonResponse
    {
        try {
            $result = $this->bot->killProcess($pid);
            $this->audit("kill-pid-{$pid}", $result['success'], $result['message'] ?? '');

            return response()->json($result, $result['success'] ? 200 : 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Kill failed: '.$e->getMessage(),
            ], 500);
        }
    }

    private function readBotVersionFromSource(): ?string
    {
        $file = base_path('ipms_java/src/main/java/com/ivac/booking/BotVersion.java');

        if (! file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);

        return preg_match('/VERSION\s*=\s*"([^"]+)"/', $content, $m) ? $m[1] : null;
    }

    protected function audit(string $action, bool $success, string $message): void
    {
        try {
            $user = auth()->user();
            Log::channel('single')->info('Bot control audit', [
                'action' => $action,
                'success' => $success,
                'message' => $message,
                'user_id' => $user?->id,
                'user_email' => $user?->email,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
