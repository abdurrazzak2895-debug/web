<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import {
    PlayCircle, StopCircle, RefreshCw, Cpu, Terminal,
    Copy, Check, BookOpen, Trash2, KeyRound,
} from 'lucide-vue-next';
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { useToast } from 'vue-toastification';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

const toast = useToast();

type GenerationMode = 'all' | 'active';

interface CaptchaStatus {
    running: boolean;
    generation_mode: GenerationMode;
    active_providers: number;
    total_providers: number;
    pool_limit: number;
    pool_expiry_seconds: number;
    slots_per_provider: number;
    pool_ready: number;
    pool_pending: number;
    pool_processing: number;
    pool_claimed: number;
    provider_counts: Record<string, number>;
}
interface CaptchaProvider {
    id: number;
    name: string;
    type: string;
    enabled: boolean;
}
interface PoolToken {
    id: number;
    token: string;
    provider_name: string;
    provider_type: string;
    solved_at: string | null;
}

// ── State ─────────────────────────────────────────────────────────────────────
const status = ref<CaptchaStatus | null>(null);
const providers = ref<CaptchaProvider[]>([]);
const poolTokens = ref<PoolToken[]>([]);

const statusLoading = ref(true);
const providersLoading = ref(true);
const tokensLoading = ref(true);
const actionLoading = ref(false);

const poolLimitInput = ref(100);
const poolLimitSaving = ref(false);
const poolExpiryInput = ref(120);
const poolExpirySaving = ref(false);
const slotsPerProviderInput = ref(5);
const slotsPerProviderSaving = ref(false);

const selectedTokenIds = ref<number[]>([]);
const deleteSelectedLoading = ref(false);
const deleteExpiredLoading = ref(false);

const activeTab = ref<'pool' | 'api'>('pool');

// ── Computed ──────────────────────────────────────────────────────────────────
const enabledProviders = computed(() => providers.value.filter(p => p.enabled));
const inactiveProviderCount = computed(() => providers.value.length - enabledProviders.value.length);

// The providers the *current* mode generates from — what the slot ceiling and the
// provider table must be sized against, not simply every row.
const activeProviders = computed(() =>
    status.value?.generation_mode === 'all' ? providers.value : enabledProviders.value,
);

const canStartAll = computed(() => providers.value.length > 0);
const canStartActive = computed(() => enabledProviders.value.length > 0);
const totalPool = computed(() =>
    (status.value?.pool_ready ?? 0)
    + (status.value?.pool_pending ?? 0)
    + (status.value?.pool_processing ?? 0),
);
const allSelected = computed(() =>
    poolTokens.value.length > 0 && poolTokens.value.every(t => selectedTokenIds.value.includes(t.id)),
);

// ── Fetch ─────────────────────────────────────────────────────────────────────
const fetchStatus = async () => {
    try {
        const r = await axios.get('/api/captcha/control/status');
        status.value = r.data;
        if (statusLoading.value) {
            poolLimitInput.value = r.data.pool_limit ?? 100;
            poolExpiryInput.value = r.data.pool_expiry_seconds ?? 120;
            slotsPerProviderInput.value = r.data.slots_per_provider ?? 5;
        }
    } catch { /* silent */ } finally {
        statusLoading.value = false;
    }
};

const fetchProviders = async () => {
    try {
        const r = await axios.get('/api/captcha-providers', { params: { per_page: 50 } });
        providers.value = r.data.data ?? r.data;
    } catch { /* silent */ } finally {
        providersLoading.value = false;
    }
};

const fetchTokens = async () => {
    try {
        const r = await axios.get('/api/captcha/pool-tokens');
        poolTokens.value = r.data;
    } catch { /* silent */ } finally {
        tokensLoading.value = false;
    }
};

// ── Actions ───────────────────────────────────────────────────────────────────
const startGeneration = async (mode: GenerationMode) => {
    actionLoading.value = true;
    try {
        const { data } = await axios.post('/api/captcha/control/start', { mode });
        const names: string[] = data.providers ?? [];
        const label = mode === 'all' ? 'all providers' : 'active providers only';
        toast.success(`Generating from ${label} (${names.length}): ${names.join(', ')}`);
        await Promise.all([fetchStatus(), fetchProviders()]);
    } catch (e: any) {
        // The server explains an impossible start in `detail`.
        toast.error(e?.response?.data?.detail ?? e?.response?.data?.error ?? 'Failed to start.');
    } finally {
        actionLoading.value = false;
    }
};

const stopGeneration = async () => {
    actionLoading.value = true;
    try {
        await axios.post('/api/captcha/control/stop');
        toast.success('Pool filler stopped.');
        await Promise.all([fetchStatus(), fetchProviders()]);
    } catch (e: any) {
        toast.error(e?.response?.data?.detail ?? e?.response?.data?.error ?? 'Failed to stop.');
    } finally {
        actionLoading.value = false;
    }
};

let poolLimitTimer: ReturnType<typeof setTimeout> | null = null;
const onPoolLimitChange = (v: number) => {
    if (poolLimitTimer) clearTimeout(poolLimitTimer);
    poolLimitTimer = setTimeout(async () => {
        if (v < 1 || v > 500) return;
        poolLimitSaving.value = true;
        try { await axios.put('/api/captcha/pool-limit', { limit: v }); }
        catch { toast.error('Failed to update pool limit.'); }
        finally { poolLimitSaving.value = false; }
    }, 600);
};

let slotsTimer: ReturnType<typeof setTimeout> | null = null;
const onSlotsPerProviderChange = (v: number) => {
    if (slotsTimer) clearTimeout(slotsTimer);
    slotsTimer = setTimeout(async () => {
        if (v < 1 || v > 20) return;
        slotsPerProviderSaving.value = true;
        try { await axios.put('/api/captcha/slots-per-provider', { slots: v }); }
        catch { toast.error('Failed to update slots per provider.'); }
        finally { slotsPerProviderSaving.value = false; }
    }, 600);
};

const savePoolExpiry = async () => {
    const v = poolExpiryInput.value;
    if (v < 30 || v > 600) return;
    poolExpirySaving.value = true;
    try {
        await axios.put('/api/captcha/pool-expiry', { seconds: v });
        toast.success('Expiry updated.');
    } catch {
        toast.error('Failed to update expiry.');
    } finally {
        poolExpirySaving.value = false;
    }
};

// ── Token selection ───────────────────────────────────────────────────────────
const toggleSelectAll = (checked: boolean | 'indeterminate') => {
    selectedTokenIds.value = checked === true ? poolTokens.value.map(t => t.id) : [];
};
const toggleSelect = (id: number) => {
    selectedTokenIds.value = selectedTokenIds.value.includes(id)
        ? selectedTokenIds.value.filter(i => i !== id)
        : [...selectedTokenIds.value, id];
};
const deleteSelected = async () => {
    deleteSelectedLoading.value = true;
    try {
        await axios.delete('/api/captcha/pool-tokens', { data: { ids: selectedTokenIds.value } });
        selectedTokenIds.value = [];
        await fetchTokens();
        fetchStatus();
    } catch {
        toast.error('Failed to delete tokens.');
    } finally {
        deleteSelectedLoading.value = false;
    }
};
const deleteExpired = async () => {
    deleteExpiredLoading.value = true;
    try {
        const { data } = await axios.delete('/api/captcha/pool-tokens/expired');
        toast.success(`Deleted ${data.deleted} expired token${data.deleted === 1 ? '' : 's'}.`);
        selectedTokenIds.value = [];
        await fetchTokens();
        fetchStatus();
    } catch {
        toast.error('Failed to delete expired tokens.');
    } finally {
        deleteExpiredLoading.value = false;
    }
};

// ── SSH commands ──────────────────────────────────────────────────────────────
const sshCmds = [
    { label: 'Start',  cmd: 'systemctl start ipms-captcha-pool' },
    { label: 'Stop',   cmd: 'systemctl stop ipms-captcha-pool' },
    { label: 'Status', cmd: 'systemctl status ipms-captcha-pool' },
    { label: 'Logs',   cmd: 'journalctl -u ipms-captcha-pool -f' },
];
const copiedCmd = ref<string | null>(null);
const copyCmd = async (cmd: string) => {
    try {
        await navigator.clipboard.writeText(cmd);
        copiedCmd.value = cmd;
        setTimeout(() => { copiedCmd.value = null; }, 1500);
    } catch {
        toast.error('Failed to copy to clipboard');
    }
};

// ── Token copy ────────────────────────────────────────────────────────────────
const copiedTokenId = ref<number | null>(null);
const copyToken = async (t: PoolToken) => {
    try {
        await navigator.clipboard.writeText(t.token);
        copiedTokenId.value = t.id;
        setTimeout(() => { copiedTokenId.value = null; }, 1500);
    } catch {
        toast.error('Failed to copy to clipboard');
    }
};

// ── Snippet copy ──────────────────────────────────────────────────────────────
const copiedSnippet = ref<string | null>(null);
const copySnippet = async (key: string, text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        copiedSnippet.value = key;
        setTimeout(() => { copiedSnippet.value = null; }, 1500);
    } catch {
        toast.error('Failed to copy to clipboard');
    }
};

// ── Helpers ───────────────────────────────────────────────────────────────────
const providerTypeLabel = (type: string) => ({
    capmonster: 'CapMonster', '2captcha': '2Captcha',
    captchaai: 'CaptchaAI', capsolver: 'CapSolver', solvecaptcha: 'SolveCaptcha',
}[type] ?? type);

const tokenAge = (iso: string | null) => {
    if (!iso) return '—';
    const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m${s % 60}s`;
};

// ── API Tester ────────────────────────────────────────────────────────────────
const testerPhone = ref('');
const testerRequestId = ref('');
const testerType = ref<'turnstile' | 'turnstile_encrypted'>('turnstile');
const testerPostRes = ref<{ status: number; data: unknown } | null>(null);
const testerGetRes = ref<{ status: number; data: unknown } | null>(null);
const testerPostLoading = ref(false);
const testerPolling = ref(false);

const runPost = async () => {
    testerPostLoading.value = true;
    testerPostRes.value = null;
    testerGetRes.value = null;
    try {
        const body: Record<string, string> = {};
        if (testerPhone.value.trim()) { body.phone = testerPhone.value.trim(); }
        const r = await axios.post('/api/captcha/request', body);
        testerPostRes.value = { status: r.status, data: r.data };
        if (r.data?.request_id) { testerRequestId.value = r.data.request_id; }
    } catch (e: any) {
        testerPostRes.value = { status: e.response?.status ?? 0, data: e.response?.data ?? e.message };
    } finally {
        testerPostLoading.value = false;
    }
};
const runGet = async () => {
    if (!testerRequestId.value) return;
    testerGetRes.value = null;
    try {
        const r = await axios.get(`/api/captcha/request/${testerRequestId.value}`, { params: { type: testerType.value } });
        testerGetRes.value = { status: r.status, data: r.data };
    } catch (e: any) {
        testerGetRes.value = { status: e.response?.status ?? 0, data: e.response?.data ?? e.message };
    }
};
const pollUntilReady = async () => {
    if (!testerRequestId.value) return;
    testerPolling.value = true;
    testerGetRes.value = null;
    while (testerPolling.value) {
        try {
            const r = await axios.get(`/api/captcha/request/${testerRequestId.value}`, { params: { type: testerType.value } });
            testerGetRes.value = { status: r.status, data: r.data };
            if (r.data?.status !== 'pending') break;
        } catch (e: any) {
            testerGetRes.value = { status: e.response?.status ?? 0, data: e.response?.data ?? e.message };
            break;
        }
        await new Promise(resolve => setTimeout(resolve, 250));
    }
    testerPolling.value = false;
};
const runFullFlow = async () => {
    await runPost();
    if (testerRequestId.value) await pollUntilReady();
};
const formatJson = (data: unknown) => JSON.stringify(data, null, 2);
const testerPostResColor = computed(() => {
    const s = testerPostRes.value?.status ?? 0;
    if (s >= 200 && s < 300) return 'text-emerald-700 dark:text-emerald-400';
    if (s >= 400) return 'text-red-600 dark:text-red-400';
    return 'text-zinc-600 dark:text-zinc-400';
});
const testerGetResColor = computed(() => {
    const d = testerGetRes.value?.data as any;
    if (d?.status === 'ready') return 'text-emerald-700 dark:text-emerald-400';
    if (d?.status === 'failed') return 'text-red-600 dark:text-red-400';
    return 'text-amber-700 dark:text-amber-400';
});

// ── Doc snippets ──────────────────────────────────────────────────────────────
const postRequest = `POST /api/captcha/request
Authorization: Bearer <CAPTCHA_API_TOKEN>
Content-Type: application/json

{
  "phone": "01700000000"   // optional
}`;
const postResponse201 = `HTTP 201 Created

{
  "request_id": "018eab2c-...",
  "status": "pending"
}`;
const postResponse200 = `HTTP 200 OK

{
  "request_id": "018eab2c-...",
  "status": "ready",
  "token": "0.AbCd..."        // claimed from pool
}`;
const getRequest = `GET /api/captcha/request/{request_id}?type=turnstile
GET /api/captcha/request/{request_id}?type=turnstile_encrypted

Authorization: Bearer <CAPTCHA_API_TOKEN>`;
const getResponsePending = `HTTP 200 OK

{
  "status": "pending"         // keep polling every 250ms
}`;
const getResponseReady = `HTTP 200 OK

{
  "status": "ready",
  "token": "0.AbCd...",
  "solved_at_ms": 1714000000000
                              // row deleted after this read
}`;

// ── Lifecycle ─────────────────────────────────────────────────────────────────
let ticker: ReturnType<typeof setInterval>;
onMounted(() => {
    fetchStatus(); fetchProviders(); fetchTokens();
    ticker = setInterval(() => { fetchStatus(); fetchTokens(); }, 2000);
});
onUnmounted(() => { clearInterval(ticker); testerPolling.value = false; });

const breadcrumbs = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Captcha Control', href: '/captcha-control' },
];
</script>

<template>
    <Head title="Captcha Control" />
    <AppLayout :breadcrumbs="breadcrumbs" full-width>
        <div class="flex h-full w-full flex-1 flex-col gap-4 p-3 sm:p-4 md:p-6 min-h-0">

            <!-- Header -->
            <div class="shrink-0 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 shadow-sm shadow-violet-500/30">
                        <Cpu class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Captcha Control</h1>
                        <p class="text-muted-foreground mt-0.5 text-sm">Manage captcha solving workers and token pool.</p>
                    </div>
                </div>
            </div>

            <div class="flex-1 flex flex-col min-h-0">
                <!-- Tab nav -->
                <div class="mb-4 flex gap-0 border-b border-zinc-200 dark:border-zinc-800 shrink-0">
                    <button
                        v-for="tab in [{ id: 'pool', label: 'Pool & Providers' }, { id: 'api', label: 'API & Tools' }]"
                        :key="tab.id"
                        @click="activeTab = tab.id as 'pool' | 'api'"
                        :class="[
                            'cursor-pointer shrink-0 whitespace-nowrap px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px',
                            activeTab === tab.id
                                ? 'border-violet-500 text-violet-600 dark:text-violet-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'
                        ]"
                    >{{ tab.label }}</button>
                </div>

                <!-- ── Tab 1: Pool & Providers ── -->
                <div v-if="activeTab === 'pool'" class="flex flex-col flex-1 min-h-0">

                    <!-- Toolbar -->
                    <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm overflow-hidden flex flex-col flex-1 min-h-0">
                        <div class="shrink-0 flex flex-wrap items-center gap-2 border-b border-zinc-200/60 dark:border-zinc-700/60 bg-white dark:bg-zinc-950 px-3 py-2">

                            <!-- Stats inline -->
                            <div class="flex items-center gap-3 text-[11px] text-zinc-400 dark:text-zinc-500 font-mono mr-1">
                                <span>
                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ status?.pool_ready ?? 0 }}</span>
                                    ready
                                </span>
                                <span class="text-zinc-300 dark:text-zinc-700">·</span>
                                <span>
                                    <span class="font-semibold text-blue-600 dark:text-blue-400">{{ (status?.pool_pending ?? 0) + (status?.pool_processing ?? 0) }}</span>
                                    solving
                                </span>
                                <span class="text-zinc-300 dark:text-zinc-700">·</span>
                                <span>
                                    <span class="font-semibold text-violet-600 dark:text-violet-400">{{ totalPool }}</span>
                                    / {{ status?.pool_limit ?? poolLimitInput }} pool
                                </span>
                                <span class="text-zinc-300 dark:text-zinc-700">·</span>
                                <span>
                                    <span class="font-semibold text-amber-600 dark:text-amber-400">{{ enabledProviders.length }}</span>
                                    active
                                    <span v-if="inactiveProviderCount > 0" class="text-zinc-300 dark:text-zinc-600">
                                        (+{{ inactiveProviderCount }} off)
                                    </span>
                                </span>
                            </div>

                            <div class="h-4 w-px bg-zinc-200 dark:bg-zinc-700"></div>

                            <!-- Running: one Stop, plus which mode is live.
                                 Stopped: the two Start modes. Each is blocked when it has
                                 nothing to draw from, since the filler would produce nothing. -->
                            <template v-if="status?.running">
                                <span
                                    class="rounded-md px-2 py-1 text-[10px] font-semibold uppercase tracking-wide"
                                    :class="status.generation_mode === 'all'
                                        ? 'bg-amber-100 text-black'
                                        : 'bg-emerald-100 text-black'"
                                >
                                    {{ status.generation_mode === 'all' ? 'All providers' : 'Active only' }}
                                </span>
                                <button
                                    :disabled="actionLoading"
                                    @click="stopGeneration"
                                    class="flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                    :class="actionLoading ? 'text-zinc-300 dark:text-zinc-600' : 'cursor-pointer text-red-600 dark:text-red-400'"
                                >
                                    <RefreshCw v-if="actionLoading" class="h-3.5 w-3.5 animate-spin shrink-0" />
                                    <StopCircle v-else class="h-3.5 w-3.5 shrink-0" />
                                    {{ actionLoading ? 'Working…' : 'Stop' }}
                                </button>
                            </template>

                            <template v-else>
                                <button
                                    :disabled="actionLoading || !canStartAll"
                                    @click="startGeneration('all')"
                                    :title="canStartAll
                                        ? 'Generate from every provider, including disabled ones — the original behaviour.'
                                        : 'Add a provider on Captcha Providers first.'"
                                    class="flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                    :class="actionLoading || !canStartAll
                                        ? 'text-zinc-300 dark:text-zinc-600'
                                        : 'cursor-pointer text-amber-600 dark:text-amber-400'"
                                >
                                    <RefreshCw v-if="actionLoading" class="h-3.5 w-3.5 animate-spin shrink-0" />
                                    <PlayCircle v-else class="h-3.5 w-3.5 shrink-0" />
                                    Start · all ({{ providers.length }})
                                </button>

                                <button
                                    :disabled="actionLoading || !canStartActive"
                                    @click="startGeneration('active')"
                                    :title="canStartActive
                                        ? 'Generate only from providers switched on in Captcha Providers.'
                                        : 'Every provider is disabled — enable one on Captcha Providers first.'"
                                    class="flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                    :class="actionLoading || !canStartActive
                                        ? 'text-zinc-300 dark:text-zinc-600'
                                        : 'cursor-pointer text-emerald-600 dark:text-emerald-400'"
                                >
                                    <RefreshCw v-if="actionLoading" class="h-3.5 w-3.5 animate-spin shrink-0" />
                                    <PlayCircle v-else class="h-3.5 w-3.5 shrink-0" />
                                    Start · active only ({{ enabledProviders.length }})
                                </button>
                            </template>

                            <div class="h-4 w-px bg-zinc-200 dark:bg-zinc-700"></div>

                            <!-- Pool limit -->
                            <div class="flex items-center gap-1.5">
                                <Label for="pool-limit" class="text-xs text-zinc-500 whitespace-nowrap">Limit</Label>
                                <Input id="pool-limit" type="number" min="1" max="500" class="h-7 w-[6.25rem] text-xs"
                                    v-model.number="poolLimitInput"
                                    @update:model-value="(v: number) => onPoolLimitChange(v)" />
                                <span v-if="poolLimitSaving" class="text-[10px] text-zinc-400 animate-pulse">saving…</span>
                            </div>

                            <div class="h-4 w-px bg-zinc-200 dark:bg-zinc-700"></div>

                            <!-- Expiry -->
                            <div class="flex items-center gap-1.5">
                                <Label for="pool-expiry" class="text-xs text-zinc-500 whitespace-nowrap">Expiry (s)</Label>
                                <Input id="pool-expiry" type="number" min="30" max="600" class="h-7 w-[6.25rem] text-xs"
                                    v-model.number="poolExpiryInput" />
                                <button
                                    :disabled="poolExpirySaving"
                                    @click="savePoolExpiry"
                                    class="cursor-pointer flex items-center gap-1 rounded-md border border-zinc-200 dark:border-zinc-700 px-2.5 py-1 text-xs font-medium text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-all active:scale-95 disabled:opacity-50"
                                >{{ poolExpirySaving ? 'Saving…' : 'Save' }}</button>
                            </div>

                            <div class="h-4 w-px bg-zinc-200 dark:bg-zinc-700"></div>

                            <!-- Slots/provider -->
                            <div class="flex items-center gap-1.5">
                                <Label for="slots-per-provider" class="text-xs text-zinc-500 whitespace-nowrap">Slots/provider</Label>
                                <Input id="slots-per-provider" type="number" min="1" max="20" class="h-7 w-[6.25rem] text-xs"
                                    v-model.number="slotsPerProviderInput"
                                    @update:model-value="(v: number) => onSlotsPerProviderChange(v)" />
                                <span v-if="slotsPerProviderSaving" class="text-[10px] text-zinc-400 animate-pulse">saving…</span>
                                <span v-else class="text-[10px] text-zinc-400">max {{ (status?.slots_per_provider ?? slotsPerProviderInput) * (activeProviders.length || 1) }}</span>
                            </div>

                            <!-- Refresh -->
                            <button
                                @click="fetchStatus(); fetchProviders(); fetchTokens();"
                                class="cursor-pointer flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-all active:scale-95 ml-auto"
                            >
                                <RefreshCw class="h-3.5 w-3.5" /> Refresh
                            </button>
                        </div>

                        <!-- Active Providers table -->
                        <div class="border-b border-zinc-200/60 dark:border-zinc-700/60 shrink-0">
                            <div class="flex items-center justify-between px-3 py-2 border-b border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40">
                                <div class="flex items-center gap-2">
                                    <Cpu class="h-3.5 w-3.5 text-zinc-400" />
                                    <span class="text-[10px] uppercase tracking-widest font-semibold text-zinc-500 dark:text-zinc-400">
                                        {{ status?.generation_mode === 'all' ? 'Generating From · All' : 'Generating From · Active' }}
                                    </span>
                                </div>
                                <Badge variant="outline" class="text-[10px] h-5 font-mono">{{ activeProviders.length }}</Badge>
                            </div>
                            <Table>
                                <TableHeader>
                                    <TableRow class="bg-zinc-50/40 dark:bg-zinc-900/20 border-b border-zinc-200/60 dark:border-zinc-700/60">
                                        <TableHead class="pl-3 pr-2 py-1.5 text-center text-[9px] uppercase tracking-widest text-zinc-400 border-r w-8">S/N</TableHead>
                                        <TableHead class="px-3 py-1.5 text-[9px] uppercase tracking-widest text-zinc-400">Name</TableHead>
                                        <TableHead class="px-3 py-1.5 text-[9px] uppercase tracking-widest text-zinc-400">Type</TableHead>
                                        <TableHead class="px-3 py-1.5 text-right text-[9px] uppercase tracking-widest text-zinc-400 border-l">Solved</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="providersLoading" v-for="i in 3" :key="i">
                                        <TableCell v-for="j in 4" :key="j" class="py-1.5"><div class="h-4 animate-pulse rounded bg-muted"></div></TableCell>
                                    </TableRow>
                                    <TableRow v-else-if="activeProviders.length === 0">
                                        <TableCell colspan="4" class="py-6 text-center text-xs text-muted-foreground">
                                            <span v-if="providers.length === 0">No providers configured.</span>
                                            <span v-else>
                                                All {{ inactiveProviderCount }} provider{{ inactiveProviderCount === 1 ? ' is' : 's are' }} disabled &mdash;
                                                enable one, or start with <span class="font-medium">all providers</span>.
                                            </span>
                                            <a href="/captcha-providers" class="ml-1 font-medium text-emerald-600 underline underline-offset-2 dark:text-emerald-400">
                                                Captcha Providers
                                            </a>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow v-else v-for="(p, i) in activeProviders" :key="p.id"
                                        class="hover:bg-zinc-50/80 dark:hover:bg-zinc-900/60 transition-colors">
                                        <TableCell class="pl-3 pr-2 py-1 text-center text-[10px] text-zinc-400 font-mono border-r">{{ i + 1 }}</TableCell>
                                        <TableCell class="px-3 py-1">
                                            <div class="flex items-center gap-1.5">
                                                <div class="h-5 w-5 rounded bg-violet-100 dark:bg-violet-900/20 flex items-center justify-center shrink-0">
                                                    <Cpu class="h-3 w-3 text-violet-500" />
                                                </div>
                                                <span class="text-[11px] font-medium">{{ p.name }}</span>
                                                <!-- Only reachable in all-providers mode; in active-only mode the row is filtered out. -->
                                                <Badge v-if="!p.enabled" variant="outline" class="text-[8px] h-4 font-mono text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-800">Paused</Badge>
                                            </div>
                                        </TableCell>
                                        <TableCell class="px-3 py-1">
                                            <Badge variant="outline" class="text-[9px] h-4 font-mono">{{ providerTypeLabel(p.type) }}</Badge>
                                        </TableCell>
                                        <TableCell class="px-3 py-1 text-right border-l">
                                            <span class="font-mono text-xs font-semibold tabular-nums"
                                                :class="(status?.provider_counts?.[String(p.id)] ?? 0) > 0
                                                    ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400'">
                                                {{ status?.provider_counts?.[String(p.id)] ?? 0 }}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>

                        <!-- Live Pool Token Table -->
                        <div class="flex flex-col flex-1 min-h-0">
                            <!-- Sub-header -->
                            <div class="shrink-0 flex items-center justify-between px-3 py-2 border-b border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40">
                                <div class="flex items-center gap-2">
                                    <KeyRound class="h-3.5 w-3.5 text-zinc-400" />
                                    <span class="text-[10px] uppercase tracking-widest font-semibold text-zinc-500 dark:text-zinc-400">Live Pool</span>
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" v-if="status?.running"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        :disabled="deleteExpiredLoading"
                                        @click="deleteExpired"
                                        title="Delete every pool token older than the configured expiry."
                                        class="cursor-pointer flex items-center gap-1 rounded-md border border-zinc-200 dark:border-zinc-700 px-2.5 py-1 text-xs font-medium text-red-500 dark:text-red-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-all active:scale-95 disabled:opacity-50"
                                    >
                                        <Trash2 class="h-3 w-3" /> {{ deleteExpiredLoading ? 'Deleting…' : 'Delete Expired' }}
                                    </button>
                                    <button
                                        v-if="selectedTokenIds.length > 0"
                                        :disabled="deleteSelectedLoading"
                                        @click="deleteSelected"
                                        class="cursor-pointer flex items-center gap-1 rounded-md border border-zinc-200 dark:border-zinc-700 px-2.5 py-1 text-xs font-medium text-red-500 dark:text-red-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-all active:scale-95 disabled:opacity-50"
                                    >
                                        <Trash2 class="h-3 w-3" /> Delete {{ selectedTokenIds.length }}
                                    </button>
                                    <Badge variant="outline"
                                        class="text-[10px] font-mono tabular-nums transition-all duration-300"
                                        :class="(status?.pool_ready ?? 0) > 0 ? 'text-emerald-600 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800' : ''">
                                        {{ status?.pool_ready ?? 0 }} ready
                                    </Badge>
                                </div>
                            </div>

                            <!-- Column headers -->
                            <div class="shrink-0 grid grid-cols-[28px_24px_1fr_56px] gap-0 divide-x divide-zinc-200/60 dark:divide-zinc-700/60 border-b border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/40 dark:bg-zinc-900/20 px-2 py-1.5">
                                <span class="text-[9px] uppercase tracking-widest text-zinc-400 text-center">S/N</span>
                                <div class="flex items-center justify-center">
                                    <Checkbox :model-value="allSelected" @update:model-value="toggleSelectAll" class="h-3.5 w-3.5" />
                                </div>
                                <span class="text-[9px] uppercase tracking-widest text-zinc-400 pl-1.5">Token · Provider</span>
                                <span class="text-[9px] uppercase tracking-widest text-zinc-400 text-right pr-1 pl-1.5">Age</span>
                            </div>

                            <!-- Rows — fills all remaining height -->
                            <div class="flex-1 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800/60">
                                <div v-if="tokensLoading" v-for="i in 5" :key="i"
                                    class="grid grid-cols-[28px_24px_1fr_56px] gap-0 divide-x divide-zinc-100 dark:divide-zinc-800/60 px-2 py-2 items-start">
                                    <div class="h-3 w-4 animate-pulse rounded bg-muted mx-auto mt-0.5"></div>
                                    <div class="h-3 w-3 animate-pulse rounded bg-muted mx-auto mt-0.5"></div>
                                    <div class="flex flex-col gap-1 pl-1.5">
                                        <div class="h-3 w-full animate-pulse rounded bg-muted"></div>
                                        <div class="h-2.5 w-2/3 animate-pulse rounded bg-muted"></div>
                                    </div>
                                    <div class="h-3 w-10 animate-pulse rounded bg-muted ml-auto mt-0.5"></div>
                                </div>

                                <div v-else-if="poolTokens.length === 0"
                                    class="flex flex-col items-center justify-center py-10 gap-2 text-zinc-400">
                                    <KeyRound class="h-5 w-5 text-zinc-300 dark:text-zinc-600" />
                                    <span class="text-[11px]">{{ status?.running ? 'Filling pool…' : 'Pool empty' }}</span>
                                </div>

                                <div v-else v-for="(t, i) in poolTokens" :key="t.id"
                                    class="grid grid-cols-[28px_24px_1fr_56px] gap-0 divide-x divide-zinc-100 dark:divide-zinc-800/60 px-2 py-2 items-start transition-colors group cursor-pointer"
                                    :class="selectedTokenIds.includes(t.id)
                                        ? 'bg-violet-50/60 dark:bg-violet-950/20'
                                        : 'hover:bg-zinc-100/70 dark:hover:bg-zinc-800/40'"
                                    @click="toggleSelect(t.id)"
                                >
                                    <span class="text-[9px] text-zinc-400 font-mono text-center tabular-nums pt-0.5">{{ i + 1 }}</span>
                                    <div class="flex items-center justify-center pt-0.5" @click.stop>
                                        <Checkbox :model-value="selectedTokenIds.includes(t.id)" @update:model-value="() => toggleSelect(t.id)" class="h-3.5 w-3.5" />
                                    </div>
                                    <div class="flex flex-col gap-0.5 pl-1.5 min-w-0">
                                        <div class="flex items-start gap-1.5">
                                            <span class="font-mono text-[10px] text-zinc-600 dark:text-zinc-300 break-all leading-relaxed flex-1">{{ t.token }}</span>
                                            <button
                                                class="cursor-pointer flex items-center justify-center h-5 w-5 rounded shrink-0 mt-0.5 transition-all opacity-0 group-hover:opacity-100"
                                                :class="copiedTokenId === t.id ? 'text-emerald-500' : 'text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300'"
                                                @click.stop="copyToken(t)"
                                            >
                                                <Check v-if="copiedTokenId === t.id" class="h-3 w-3" />
                                                <Copy v-else class="h-3 w-3" />
                                            </button>
                                        </div>
                                        <span class="text-[9px] text-zinc-400">{{ t.provider_name }}</span>
                                    </div>
                                    <span class="text-[10px] text-zinc-400 text-right tabular-nums font-mono pr-1 pl-1.5 pt-0.5">{{ tokenAge(t.solved_at) }}</span>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="shrink-0 px-3 py-1.5 border-t border-zinc-200/60 dark:border-zinc-700/60 flex items-center justify-between">
                                <span class="text-[9px] text-zinc-400">auto-refreshes every 2s · expires after {{ status?.pool_expiry_seconds ?? poolExpiryInput }}s</span>
                                <span class="text-[9px] text-zinc-400 font-mono tabular-nums">{{ poolTokens.length }} shown</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Tab 2: API & Tools ── -->
                <div v-if="activeTab === 'api'" class="flex-1 overflow-y-auto flex flex-col gap-4 pb-4">

                    <!-- SSH Commands -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex items-center gap-2 px-3 py-2 border-b border-zinc-200 dark:border-zinc-800">
                            <Terminal class="h-3.5 w-3.5 text-zinc-400" />
                            <span class="text-[10px] uppercase tracking-widest font-semibold text-zinc-400">SSH Commands</span>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-zinc-200/60 dark:divide-zinc-700/60">
                            <button v-for="item in sshCmds" :key="item.cmd"
                                class="cursor-pointer group relative flex flex-col gap-0.5 px-3 py-2.5 text-left hover:bg-zinc-50 dark:hover:bg-zinc-900/60 transition-colors"
                                @click="copyCmd(item.cmd)"
                            >
                                <span class="text-[9px] font-semibold uppercase tracking-widest text-zinc-400">{{ item.label }}</span>
                                <code class="font-mono text-[10px] text-zinc-600 dark:text-zinc-400 truncate">{{ item.cmd }}</code>
                                <span class="absolute right-2 top-2 text-[9px] font-semibold transition-all"
                                    :class="copiedCmd === item.cmd ? 'text-emerald-500 opacity-100' : 'text-zinc-400 opacity-0 group-hover:opacity-100'">
                                    {{ copiedCmd === item.cmd ? 'Copied!' : 'Copy' }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- API Documentation -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex items-center justify-between px-3 py-2 border-b border-zinc-200 dark:border-zinc-800">
                            <div class="flex items-center gap-2">
                                <BookOpen class="h-3.5 w-3.5 text-zinc-400" />
                                <span class="text-[10px] uppercase tracking-widest font-semibold text-zinc-400">API Documentation</span>
                            </div>
                            <span class="text-[10px] text-zinc-400">Bearer token or admin session</span>
                        </div>

                        <!-- Flow -->
                        <div class="flex items-center gap-0 px-4 py-2.5 bg-zinc-50/60 dark:bg-zinc-900/40 border-b border-zinc-200/60 dark:border-zinc-700/60 overflow-x-auto">
                            <div class="flex items-center gap-1.5 shrink-0">
                                <span class="flex items-center justify-center h-4 w-4 rounded-full bg-blue-100 dark:bg-blue-900/40 text-[9px] font-bold text-blue-600 dark:text-blue-400">1</span>
                                <span class="text-[10px] font-medium text-zinc-600 dark:text-zinc-300">POST /request</span>
                                <span class="text-[9px] text-zinc-400">→ get request_id</span>
                            </div>
                            <svg class="mx-3 h-3 w-3 shrink-0 text-zinc-300 dark:text-zinc-600" fill="none" viewBox="0 0 16 16"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <span class="flex items-center justify-center h-4 w-4 rounded-full bg-amber-100 dark:bg-amber-900/40 text-[9px] font-bold text-amber-600 dark:text-amber-400">2</span>
                                <span class="text-[10px] font-medium text-zinc-600 dark:text-zinc-300">GET /request/{'{id}'}?type=…</span>
                                <span class="text-[9px] text-zinc-400">→ poll every 250ms</span>
                            </div>
                            <svg class="mx-3 h-3 w-3 shrink-0 text-zinc-300 dark:text-zinc-600" fill="none" viewBox="0 0 16 16"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <span class="flex items-center justify-center h-4 w-4 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-[9px] font-bold text-emerald-600 dark:text-emerald-400">3</span>
                                <span class="text-[10px] font-medium text-zinc-600 dark:text-zinc-300">status=ready</span>
                                <span class="text-[9px] text-zinc-400">→ consume token</span>
                            </div>
                        </div>

                        <!-- POST endpoint -->
                        <div class="border-b border-zinc-200/60 dark:border-zinc-700/60">
                            <div class="flex items-center gap-2.5 px-4 py-2 bg-blue-50/50 dark:bg-blue-950/10 border-b border-zinc-200/60 dark:border-zinc-700/60">
                                <span class="rounded bg-blue-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-black">POST</span>
                                <code class="font-mono text-[11px] font-semibold text-zinc-700 dark:text-zinc-200">/api/captcha/request</code>
                                <span class="ml-auto inline-flex items-center gap-1 rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 text-[9px] text-zinc-400">
                                    <span class="h-1 w-1 rounded-full bg-emerald-400"></span>bearer token
                                </span>
                            </div>
                            <div class="grid grid-cols-1 lg:grid-cols-5">
                                <div class="lg:col-span-2 px-4 py-3 flex flex-col gap-3 border-b lg:border-b-0 lg:border-r border-zinc-200/60 dark:border-zinc-700/60">
                                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400 leading-relaxed">
                                        Claims a solved token from the pool instantly
                                        (<code class="font-mono bg-zinc-100 dark:bg-zinc-800 px-0.5 rounded text-[10px]">200</code>),
                                        or queues a fresh solve if the pool is empty
                                        (<code class="font-mono bg-zinc-100 dark:bg-zinc-800 px-0.5 rounded text-[10px]">201</code>).
                                    </p>
                                    <div class="flex flex-col gap-1">
                                        <span class="text-[9px] uppercase tracking-wider text-zinc-400 font-semibold">Body</span>
                                        <div class="flex items-center gap-2">
                                            <code class="font-mono text-[10px] text-blue-500 dark:text-blue-400">phone</code>
                                            <span class="text-[9px] italic text-zinc-400">optional</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="lg:col-span-3 flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700/50 bg-zinc-50 dark:bg-zinc-950">
                                    <div class="relative group px-3 py-2.5">
                                        <span class="text-[9px] uppercase tracking-wider text-zinc-500 font-semibold mb-1.5 block">Request</span>
                                        <pre class="text-emerald-700 dark:text-emerald-400 text-[10px] font-mono overflow-x-auto leading-relaxed">{{ postRequest }}</pre>
                                        <button class="cursor-pointer absolute top-2.5 right-2 opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700" @click="copySnippet('post-req', postRequest)">
                                            <Check v-if="copiedSnippet === 'post-req'" class="h-3 w-3 text-emerald-400" /><Copy v-else class="h-3 w-3 text-zinc-500" />
                                        </button>
                                    </div>
                                    <div class="relative group px-3 py-2.5">
                                        <span class="text-[9px] uppercase tracking-wider text-zinc-500 font-semibold mb-1.5 block">Response <span class="text-blue-600 dark:text-blue-400">201</span> — queued</span>
                                        <pre class="text-emerald-700 dark:text-emerald-400 text-[10px] font-mono overflow-x-auto leading-relaxed">{{ postResponse201 }}</pre>
                                        <button class="cursor-pointer absolute top-2.5 right-2 opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700" @click="copySnippet('post-201', postResponse201)">
                                            <Check v-if="copiedSnippet === 'post-201'" class="h-3 w-3 text-emerald-400" /><Copy v-else class="h-3 w-3 text-zinc-500" />
                                        </button>
                                    </div>
                                    <div class="relative group px-3 py-2.5">
                                        <span class="text-[9px] uppercase tracking-wider text-zinc-500 font-semibold mb-1.5 block">Response <span class="text-emerald-600 dark:text-emerald-400">200</span> — instant</span>
                                        <pre class="text-emerald-700 dark:text-emerald-400 text-[10px] font-mono overflow-x-auto leading-relaxed">{{ postResponse200 }}</pre>
                                        <button class="cursor-pointer absolute top-2.5 right-2 opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700" @click="copySnippet('post-200', postResponse200)">
                                            <Check v-if="copiedSnippet === 'post-200'" class="h-3 w-3 text-emerald-400" /><Copy v-else class="h-3 w-3 text-zinc-500" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- GET endpoint -->
                        <div>
                            <div class="flex items-center gap-2.5 px-4 py-2 bg-emerald-50/50 dark:bg-emerald-950/10 border-b border-zinc-200/60 dark:border-zinc-700/60">
                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-black">GET</span>
                                <code class="font-mono text-[11px] font-semibold text-zinc-700 dark:text-zinc-200">/api/captcha/request/{'{request_id}'}?type=…</code>
                                <span class="ml-auto inline-flex items-center gap-1 rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 text-[9px] text-zinc-400">
                                    <span class="h-1 w-1 rounded-full bg-emerald-400"></span>bearer token
                                </span>
                            </div>
                            <div class="grid grid-cols-1 lg:grid-cols-5">
                                <div class="lg:col-span-2 px-4 py-3 flex flex-col gap-3 border-b lg:border-b-0 lg:border-r border-zinc-200/60 dark:border-zinc-700/60">
                                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400 leading-relaxed">
                                        Poll every <code class="font-mono bg-zinc-100 dark:bg-zinc-800 px-0.5 rounded text-[10px]">250ms</code>
                                        until <code class="font-mono bg-zinc-100 dark:bg-zinc-800 px-0.5 rounded text-[10px]">status=ready</code>.
                                        Token is consumed and deleted on first read.
                                    </p>
                                    <div class="flex flex-col gap-1.5">
                                        <span class="text-[9px] uppercase tracking-wider text-zinc-400 font-semibold">type param</span>
                                        <div class="flex flex-col gap-1 pl-2 border-l-2 border-zinc-200 dark:border-zinc-700">
                                            <div class="flex items-center gap-1.5">
                                                <code class="font-mono text-[10px] text-amber-500 dark:text-amber-400">turnstile</code>
                                                <span class="text-[10px] text-zinc-500 dark:text-zinc-400">sign-in (raw)</span>
                                            </div>
                                            <div class="flex items-center gap-1.5">
                                                <code class="font-mono text-[10px] text-amber-500 dark:text-amber-400">turnstile_encrypted</code>
                                                <span class="text-[10px] text-zinc-500 dark:text-zinc-400">slot (transformed)</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="lg:col-span-3 flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700/50 bg-zinc-50 dark:bg-zinc-950">
                                    <div class="relative group px-3 py-2.5">
                                        <span class="text-[9px] uppercase tracking-wider text-zinc-500 font-semibold mb-1.5 block">Request</span>
                                        <pre class="text-emerald-700 dark:text-emerald-400 text-[10px] font-mono overflow-x-auto leading-relaxed">{{ getRequest }}</pre>
                                        <button class="cursor-pointer absolute top-2.5 right-2 opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700" @click="copySnippet('get-req', getRequest)">
                                            <Check v-if="copiedSnippet === 'get-req'" class="h-3 w-3 text-emerald-400" /><Copy v-else class="h-3 w-3 text-zinc-500" />
                                        </button>
                                    </div>
                                    <div class="relative group px-3 py-2.5">
                                        <span class="text-[9px] uppercase tracking-wider text-zinc-500 font-semibold mb-1.5 block">Response — <span class="text-amber-600 dark:text-amber-400">pending</span></span>
                                        <pre class="text-emerald-700 dark:text-emerald-400 text-[10px] font-mono overflow-x-auto leading-relaxed">{{ getResponsePending }}</pre>
                                        <button class="cursor-pointer absolute top-2.5 right-2 opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700" @click="copySnippet('get-pending', getResponsePending)">
                                            <Check v-if="copiedSnippet === 'get-pending'" class="h-3 w-3 text-emerald-400" /><Copy v-else class="h-3 w-3 text-zinc-500" />
                                        </button>
                                    </div>
                                    <div class="relative group px-3 py-2.5">
                                        <span class="text-[9px] uppercase tracking-wider text-zinc-500 font-semibold mb-1.5 block">Response — <span class="text-emerald-600 dark:text-emerald-400">ready</span></span>
                                        <pre class="text-emerald-700 dark:text-emerald-400 text-[10px] font-mono overflow-x-auto leading-relaxed">{{ getResponseReady }}</pre>
                                        <button class="cursor-pointer absolute top-2.5 right-2 opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700" @click="copySnippet('get-ready', getResponseReady)">
                                            <Check v-if="copiedSnippet === 'get-ready'" class="h-3 w-3 text-emerald-400" /><Copy v-else class="h-3 w-3 text-zinc-500" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- API Tester -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex items-center justify-between px-3 py-2 border-b border-zinc-200 dark:border-zinc-800">
                            <div class="flex items-center gap-2">
                                <svg class="h-3.5 w-3.5 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                                <span class="text-[10px] uppercase tracking-widest font-semibold text-zinc-400">API Tester</span>
                            </div>
                            <Button size="sm" variant="outline"
                                class="cursor-pointer h-6 gap-1 px-2.5 text-[10px] border-zinc-200 dark:border-zinc-700 text-blue-600 dark:text-blue-400 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                :disabled="testerPostLoading || testerPolling"
                                @click="runFullFlow"
                            >
                                <RefreshCw class="h-3 w-3" :class="(testerPostLoading || testerPolling) ? 'animate-spin' : ''" />
                                {{ (testerPostLoading || testerPolling) ? 'Running…' : 'Run Full Flow' }}
                            </Button>
                        </div>

                        <!-- POST -->
                        <div class="border-b border-zinc-200/60 dark:border-zinc-700/60">
                            <div class="flex items-center gap-2 px-4 py-2 bg-blue-50/50 dark:bg-blue-950/10 border-b border-zinc-200/60 dark:border-zinc-700/60">
                                <span class="rounded bg-blue-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-black">POST</span>
                                <code class="font-mono text-[11px] font-semibold text-zinc-700 dark:text-zinc-200">/api/captcha/request</code>
                            </div>
                            <div class="grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x divide-zinc-200/60 dark:divide-zinc-700/60">
                                <div class="px-4 py-3 flex flex-col gap-3">
                                    <div class="flex flex-col gap-1.5">
                                        <Label class="text-[10px] text-zinc-500">phone <span class="italic text-zinc-400">optional</span></Label>
                                        <Input v-model="testerPhone" placeholder="01700000000" class="h-7 text-xs font-mono" />
                                    </div>
                                    <Button size="sm" variant="outline"
                                        class="cursor-pointer h-7 w-full gap-1.5 text-[11px] text-blue-600 dark:text-blue-400"
                                        :disabled="testerPostLoading || testerPolling"
                                        @click="runPost"
                                    >
                                        {{ testerPostLoading ? 'Sending…' : 'Send' }}
                                    </Button>
                                </div>
                                <div class="bg-zinc-50 dark:bg-zinc-950 px-3 py-2.5 min-h-[5rem]">
                                    <div v-if="testerPostRes" class="flex flex-col gap-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[9px] uppercase tracking-wider text-zinc-500 font-semibold">Response</span>
                                            <span :class="testerPostResColor" class="font-mono text-[10px] font-bold">{{ testerPostRes.status }}</span>
                                        </div>
                                        <pre :class="testerPostResColor" class="text-[10px] font-mono overflow-x-auto leading-relaxed whitespace-pre-wrap break-all">{{ formatJson(testerPostRes.data) }}</pre>
                                    </div>
                                    <div v-else class="flex items-center justify-center py-6">
                                        <span class="text-[10px] text-zinc-500">Press Send to execute</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- GET -->
                        <div>
                            <div class="flex items-center gap-2 px-4 py-2 bg-emerald-50/50 dark:bg-emerald-950/10 border-b border-zinc-200/60 dark:border-zinc-700/60">
                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-black">GET</span>
                                <code class="font-mono text-[11px] font-semibold text-zinc-700 dark:text-zinc-200">/api/captcha/request/{'{id}'}?type=…</code>
                            </div>
                            <div class="grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x divide-zinc-200/60 dark:divide-zinc-700/60">
                                <div class="px-4 py-3 flex flex-col gap-3">
                                    <div class="flex flex-col gap-1.5">
                                        <Label class="text-[10px] text-zinc-500">request_id</Label>
                                        <Input v-model="testerRequestId" placeholder="018eab2c-…" class="h-7 text-xs font-mono" />
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <Label class="text-[10px] text-zinc-500">type</Label>
                                        <select v-model="testerType"
                                            class="h-7 rounded-md border border-input bg-background px-2 text-xs font-mono text-zinc-700 dark:text-zinc-200">
                                            <option value="turnstile">turnstile (raw — sign-in)</option>
                                            <option value="turnstile_encrypted">turnstile_encrypted (slot)</option>
                                        </select>
                                    </div>
                                    <div class="flex gap-2">
                                        <Button size="sm" variant="outline"
                                            class="cursor-pointer h-7 flex-1 text-[11px] text-emerald-600 dark:text-emerald-400"
                                            :disabled="!testerRequestId || testerPolling"
                                            @click="runGet"
                                        >Send once</Button>
                                        <Button size="sm" variant="outline"
                                            class="cursor-pointer h-7 flex-1 gap-1.5 text-[11px]"
                                            :class="testerPolling ? 'text-amber-600 dark:text-amber-400' : 'text-blue-600 dark:text-blue-400'"
                                            :disabled="!testerRequestId || testerPostLoading"
                                            @click="testerPolling ? (testerPolling = false) : pollUntilReady()"
                                        >
                                            <RefreshCw class="h-3 w-3" :class="testerPolling ? 'animate-spin' : ''" />
                                            {{ testerPolling ? 'Stop' : 'Poll until ready' }}
                                        </Button>
                                    </div>
                                </div>
                                <div class="bg-zinc-50 dark:bg-zinc-950 px-3 py-2.5 min-h-[6.25rem]">
                                    <div v-if="testerGetRes" class="flex flex-col gap-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[9px] uppercase tracking-wider text-zinc-500 font-semibold">Response</span>
                                            <span class="font-mono text-[10px] font-bold text-zinc-400">{{ testerGetRes.status }}</span>
                                            <span v-if="(testerGetRes.data as any)?.status" :class="testerGetResColor" class="font-mono text-[10px] font-bold">{{ (testerGetRes.data as any).status }}</span>
                                        </div>
                                        <pre :class="testerGetResColor" class="text-[10px] font-mono overflow-x-auto leading-relaxed whitespace-pre-wrap break-all">{{ formatJson(testerGetRes.data) }}</pre>
                                    </div>
                                    <div v-else-if="testerPolling" class="flex items-center gap-2 justify-center py-6">
                                        <RefreshCw class="h-3 w-3 text-zinc-500 animate-spin" />
                                        <span class="text-[10px] text-zinc-500">Polling every 250ms…</span>
                                    </div>
                                    <div v-else class="flex items-center justify-center py-6">
                                        <span class="text-[10px] text-zinc-500">Send a request or run full flow</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </AppLayout>
</template>
