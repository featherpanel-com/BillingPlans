<script setup lang="ts">
import { ref, onMounted, computed, watch } from "vue";
import { useToast } from "vue-toastification";
import {
  Loader2, CreditCard, CalendarClock, PauseCircle, XCircle, Clock,
  ShoppingCart, AlertTriangle, CheckCircle2, Server, HardDrive,
  Cpu, Database, MemoryStick, Shield, Package, Infinity, ChevronDown,
  ChevronUp, CircleDollarSign, ArrowLeft, BarChart3, RefreshCw,
} from "lucide-vue-next";
import { useUserPlansAPI, type Plan } from "@/composables/usePlansAPI";
import { useUserSubscriptionsAPI, type Subscription } from "@/composables/useSubscriptionsAPI";
import { useUserCategoriesAPI, type Category, colorClasses } from "@/composables/useCategoriesAPI";

const toast = useToast();
const { loading: plansLoading, listPlans, subscribeToPlan } = useUserPlansAPI();
const { loading: subsLoading, listSubscriptions, cancelSubscription, changeSubscriptionPlan } = useUserSubscriptionsAPI();
const { listCategories } = useUserCategoriesAPI();

type Tab = "browse" | "my-subscriptions";
type ClientShellView = "main" | "subscribe";
const activeTab = ref<Tab>("browse");
const shellView = ref<ClientShellView>("main");
const plans = ref<Plan[]>([]);
const subscriptions = ref<Subscription[]>([]);
const categories = ref<Category[]>([]);
const activeCategoryId = ref<number | null>(null);
const activeLocationId = ref<number | null>(null);
const searchQuery = ref("");
const userCredits = ref(0);


const planToSubscribe = ref<Plan | null>(null);
const serverName = ref("");
const couponCode = ref("");
const chosenRealmId = ref<number | null>(null);
const chosenSpellId = ref<number | null>(null);
const subscribing = ref(false);
const showCancelConfirm = ref(false);
const subToCancel = ref<Subscription | null>(null);
const showChangePlanConfirm = ref(false);
const subToChange = ref<Subscription | null>(null);
const targetPlanId = ref<number | null>(null);
const changingPlan = ref(false);
const expandedPlanId = ref<number | null>(null);
const showCostBreakdown = ref(false);
const PERIOD_MAP: Record<number, string> = {
  1: "Daily", 7: "Weekly", 14: "Bi-Weekly", 30: "Monthly",
  90: "Quarterly", 180: "Semi-Annual", 365: "Annual",
};
function getPeriodLabel(days: number) {
  return PERIOD_MAP[days] ?? `Every ${days}d`;
}
function formatDate(dt: string | null) {
  if (!dt) return "—";
  return new Date(dt).toLocaleDateString(undefined, {
    year: "numeric", month: "short", day: "numeric",
    hour: "2-digit", minute: "2-digit",
  });
}
function daysUntil(dt: string | null) {
  if (!dt) return "";
  const diff = Math.ceil((new Date(dt).getTime() - Date.now()) / 86400000);
  if (diff < 0) return "Overdue";
  if (diff === 0) return "Today";
  if (diff === 1) return "Tomorrow";
  return `In ${diff} days`;
}
function fmtMB(mb: number) {
  return mb >= 1024 ? (mb / 1024).toFixed(1).replace(/\.0$/, "") + " GB" : mb + " MB";
}

const loadData = async () => {
  try {
    const [pr, sr, cats] = await Promise.all([listPlans(), listSubscriptions(), listCategories()]);
    plans.value = pr.data;
    userCredits.value = pr.user_credits;
    subscriptions.value = sr.data;
    categories.value = cats;
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to load data");
  }
};

const filteredPlans = computed(() => {
  const query = searchQuery.value.trim().toLowerCase();
  return plans.value.filter((p) => {
    if (activeCategoryId.value !== null && p.category_id !== activeCategoryId.value) return false;
    if (activeLocationId.value !== null) {
      const locations = Array.isArray(p.location_ids) ? p.location_ids : [];
      if (!locations.includes(activeLocationId.value)) return false;
    }
    if (!query) return true;
    const haystack = `${p.name} ${p.description ?? ""} ${p.long_description ?? ""}`.toLowerCase();
    return haystack.includes(query);
  });
});

const availableLocationIds = computed<number[]>(() => {
  const ids = new Set<number>();
  for (const plan of plans.value) {
    const locations = Array.isArray(plan.location_ids) ? plan.location_ids : [];
    for (const locationId of locations) ids.add(locationId);
  }
  return Array.from(ids).sort((a, b) => a - b);
});

const locationOptions = computed<Array<{ id: number; label: string }>>(() => {
  const labels = new Map<number, string>();
  for (const plan of plans.value) {
    const locationIds = Array.isArray(plan.location_ids) ? plan.location_ids : [];
    const locationNames = plan.location_names ?? {};
    for (const locationId of locationIds) {
      const rawLabel = locationNames[String(locationId)];
      const label = typeof rawLabel === "string" ? rawLabel.trim() : "";
      if (label && !labels.has(locationId)) labels.set(locationId, label);
    }
  }

  return availableLocationIds.value.map((id) => ({
    id,
    label: labels.get(id) ?? `Location #${id}`,
  }));
});

function slugify(input: string): string {
  return input
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function normalizeAssetUrl(url: string): string {
  const raw = url.trim();
  if (!raw) return "";
  try {
    return new URL(raw, window.location.origin).toString();
  } catch {
    return raw;
  }
}

function isImageIcon(icon: string | null | undefined): boolean {
  if (!icon) return false;
  const value = icon.trim().toLowerCase();
  return (
    value.startsWith("http://") ||
    value.startsWith("https://") ||
    value.startsWith("/") ||
    value.startsWith("data:image/") ||
    value.endsWith(".png") ||
    value.endsWith(".jpg") ||
    value.endsWith(".jpeg") ||
    value.endsWith(".gif") ||
    value.endsWith(".webp") ||
    value.endsWith(".svg")
  );
}

function buildPlanUrl(plan: Plan): string {
  const topWindow = window.top ?? window;
  const url = new URL("/dashboard/billing/plans", topWindow.location.origin);
  const categorySlug = slugify(plan.category?.name ?? "plan");
  url.searchParams.set("plan", String(plan.id));
  url.searchParams.set("category", categorySlug);
  url.hash = `/get/${categorySlug}/${plan.id}`;
  return url.toString();
}

function cardBackgroundStyle(plan: Plan): Record<string, string> {
  const rawImage = typeof plan.card_background_image === "string" ? plan.card_background_image.trim() : "";
  let image = rawImage;
  if (image) {
    try {
      const parsed = new URL(image, window.location.origin);
      if (parsed.protocol === "http:" && window.location.protocol === "https:") {
        parsed.protocol = "https:";
      }
      image = parsed.toString();
    } catch {
      image = rawImage;
    }
  }
  if (!image) return {};

  return {
    backgroundImage: `linear-gradient(to bottom, rgb(0 0 0 / 0.45), rgb(0 0 0 / 0.7)), url("${image}")`,
    backgroundSize: "cover",
    backgroundPosition: "center",
  };
}

async function copyPlanUrl(plan: Plan) {
  const link = buildPlanUrl(plan);
  try {
    await navigator.clipboard.writeText(link);
    toast.success("Plan link copied.");
  } catch {
    toast.error("Failed to copy plan link.");
  }
}

function openPlanFromUrl() {
  const topWindow = window.top ?? window;
  const current = window.location;
  const topLocation = topWindow.location;
  const hash = topLocation.hash || current.hash || "";
  const hashMatch = hash.match(/#\/get\/[^/]+\/(\d+)/);
  if (hashMatch?.[1]) {
    const hashPlanId = Number(hashMatch[1]);
    if (Number.isFinite(hashPlanId) && hashPlanId > 0) {
      const hashPlan = plans.value.find((p) => p.id === hashPlanId);
      if (hashPlan) {
        activeCategoryId.value = hashPlan.category_id ?? null;
        startSubscribe(hashPlan);
        return;
      }
    }
  }

  const params = new URLSearchParams(topLocation.search || current.search);
  const rawPlanId = params.get("plan");
  if (!rawPlanId) return;
  const planId = Number(rawPlanId);
  if (!Number.isFinite(planId) || planId <= 0) return;
  const plan = plans.value.find((p) => p.id === planId);
  if (!plan) return;
  if (params.get("category")) {
    activeCategoryId.value = plan.category_id ?? null;
  }
  startSubscribe(plan);
}

const subscribeFilteredSpells = computed(() => {
  const plan = planToSubscribe.value;
  if (!plan?.user_can_choose_spell || !plan.allowed_spells_options?.length) return [];

  const opts = plan.allowed_spells_options;
  let realmId: number | null = null;

  if (plan.user_can_choose_realm) {
    const raw = chosenRealmId.value;
    if (raw == null) return [];
    realmId = Number(raw);
  } else if (plan.realms_id) {
    realmId = Number(plan.realms_id);
  }

  if (realmId === null || Number.isNaN(realmId)) return opts;
  return opts.filter((s) => Number(s.realm_id) === realmId);
});

const canConfirmSubscribe = computed(() => {
  const p = planToSubscribe.value;
  if (!p) return false;
  if (p.user_can_choose_realm && chosenRealmId.value == null) return false;
  if (p.user_can_choose_spell) {
    if (subscribeFilteredSpells.value.length === 0) return false;
    if (chosenSpellId.value == null) return false;
  }
  return true;
});

watch(chosenRealmId, () => {
  const spells = subscribeFilteredSpells.value;
  if (
    chosenSpellId.value !== null &&
    !spells.some((s) => s.id === chosenSpellId.value)
  ) {
    chosenSpellId.value = null;
  }
});

const closeSubscribeFlow = () => {
  shellView.value = "main";
  planToSubscribe.value = null;
  serverName.value = "";
  couponCode.value = "";
  chosenRealmId.value = null;
  chosenSpellId.value = null;
};

const customResources = ref<Record<string, number>>({});

const RESOURCES_LIST = [
  { key: "memory", name: "RAM", unit: " MB", icon: MemoryStick, iconColor: "text-blue-400" },
  { key: "cpu", name: "CPU", unit: "%", icon: Cpu, iconColor: "text-emerald-400" },
  { key: "disk", name: "Disk Space", unit: " MB", icon: HardDrive, iconColor: "text-orange-400" },
  { key: "database_limit", name: "Databases", unit: "", icon: Database, iconColor: "text-purple-400" },
  { key: "backup_limit", name: "Backups", unit: "", icon: Shield, iconColor: "text-cyan-400" },
  { key: "allocation_limit", name: "Allocations", unit: " ports", icon: Server, iconColor: "text-pink-400" },
];

const handleCustomResourceInput = (key: string, val: any) => {
  if (!planToSubscribe.value) return;
  const cfg = planToSubscribe.value.slider_config?.[key];
  if (!cfg) return;

  const baseVal = Number(cfg.base ?? 0);
  const maxVal = Number(cfg.max ?? 0);
  const stepVal = Number(cfg.step ?? 1);
  const rounding = cfg.rounding ?? "nearest";

  let num = Number(val);
  if (isNaN(num)) num = baseVal;

  num = Math.max(baseVal, Math.min(maxVal, num));

  const diff = num - baseVal;
  let steps = diff / stepVal;
  if (rounding === "up") {
    steps = Math.ceil(steps);
  } else if (rounding === "down") {
    steps = Math.floor(steps);
  } else {
    steps = Math.round(steps);
  }

  customResources.value[key] = baseVal + steps * stepVal;
};

const sliderAdditionalCredits = computed(() => {
  let extra = 0;
  if (!planToSubscribe.value) return 0;
  const config = planToSubscribe.value.slider_config;
  if (!config) return 0;
  Object.entries(config).forEach(([key, cfg]) => {
    if (cfg && cfg.enabled) {
      const baseVal = Number(cfg.base ?? 0);
      const maxVal = Number(cfg.max ?? 0);
      const stepVal = Number(cfg.step ?? 1);
      const costPerStep = Number(cfg.cost_per_step ?? 0);
      const rounding = cfg.rounding ?? "nearest";

      let selectedVal = customResources.value[key] !== undefined ? customResources.value[key] : baseVal;
      selectedVal = Math.max(baseVal, Math.min(maxVal, selectedVal));

      if (selectedVal > baseVal) {
        const diff = selectedVal - baseVal;
        let steps = diff / (stepVal || 1);
        if (rounding === "up") {
          steps = Math.ceil(steps);
        } else if (rounding === "down") {
          steps = Math.floor(steps);
        } else {
          steps = Math.round(steps);
        }
        extra += steps * costPerStep;
      }
    }
  });
  return extra;
});

const resourceCostBreakdown = computed(() => {
  const breakdown: { name: string; value: string; extraCredits: number }[] = [];
  if (!planToSubscribe.value) return breakdown;
  const config = planToSubscribe.value.slider_config;
  if (!config) return breakdown;

  const resourceNames: Record<string, string> = {
    memory: "RAM",
    cpu: "CPU",
    disk: "Disk Space",
    database_limit: "Databases",
    backup_limit: "Backups",
    allocation_limit: "Allocations",
  };

  Object.entries(config).forEach(([key, cfg]) => {
    if (cfg && cfg.enabled) {
      const baseVal = Number(cfg.base ?? 0);
      const maxVal = Number(cfg.max ?? 0);
      const stepVal = Number(cfg.step ?? 1);
      const costPerStep = Number(cfg.cost_per_step ?? 0);
      const rounding = cfg.rounding ?? "nearest";

      let selectedVal = customResources.value[key] !== undefined ? customResources.value[key] : baseVal;
      selectedVal = Math.max(baseVal, Math.min(maxVal, selectedVal));

      if (selectedVal > baseVal) {
        const diff = selectedVal - baseVal;
        let steps = diff / (stepVal || 1);
        if (rounding === "up") {
          steps = Math.ceil(steps);
        } else if (rounding === "down") {
          steps = Math.floor(steps);
        } else {
          steps = Math.round(steps);
        }
        const extraCredits = steps * costPerStep;
        if (extraCredits > 0) {
          const name = resourceNames[key] ?? key;
          let valueStr = selectedVal.toString();
          if (key === "memory" || key === "disk") {
            valueStr = fmtMB(selectedVal);
          } else if (key === "cpu") {
            valueStr = selectedVal + "%";
          }
          breakdown.push({ name, value: valueStr, extraCredits });
        }
      }
    }
  });
  return breakdown;
});

const liveTotalCredits = computed(() => {
  if (!planToSubscribe.value) return 0;
  const basePrice = Number(planToSubscribe.value.price_credits ?? 0);
  const subtotal = basePrice + sliderAdditionalCredits.value;
  const taxRate = Number(planToSubscribe.value.tax_rate_percent ?? 0);
  const extraRate = Number(planToSubscribe.value.extra_charge_percent ?? 0);

  const taxCredits = Math.round(subtotal * (taxRate / 100));
  const extraChargeCredits = Math.round(subtotal * (extraRate / 100));
  return Math.max(0, subtotal + taxCredits + extraChargeCredits);
});

const startSubscribe = (plan: Plan) => {
  if (plan.is_sold_out) {
    toast.error("This plan is sold out.");
    return;
  }
  // Initialize slider state
  customResources.value = {};
  // Debug: log slider_config to help diagnose issues
  if (plan.slider_config) {
    Object.entries(plan.slider_config).forEach(([key, cfg]) => {
      if (cfg && cfg.enabled) {
        customResources.value[key] = cfg.base;
      }
    });
  }

  planToSubscribe.value = plan;
  serverName.value = plan.name;
  couponCode.value = "";
  chosenRealmId.value = null;
  chosenSpellId.value = null;
  if (plan.user_can_choose_realm && plan.allowed_realms_options?.length === 1) {
    chosenRealmId.value = plan.allowed_realms_options[0].id;
  }
  shellView.value = "subscribe";
};

const executeSubscribe = async () => {
  if (!planToSubscribe.value) return;
  subscribing.value = true;
  try {
    const realmId =
      chosenRealmId.value != null ? Number(chosenRealmId.value) : undefined;
    const spellId =
      chosenSpellId.value != null ? Number(chosenSpellId.value) : undefined;
    const result = await subscribeToPlan(planToSubscribe.value.id, {
      server_name: serverName.value.trim() || undefined,
      chosen_realm_id: realmId,
      chosen_spell_id: spellId,
      coupon_code: couponCode.value.trim() || undefined,
      custom_resources: Object.keys(customResources.value).length > 0 ? customResources.value : undefined,
    });
    userCredits.value = result.new_credits_balance;
    const paidNow = Number(result.credits_deducted ?? 0);
    const beforeDiscount = Number(result.credits_before_discount ?? paidNow);
    const discountNow = Math.max(0, beforeDiscount - paidNow);
    toast.success(
      `Subscribed to ${planToSubscribe.value.name}! Paid ${paidNow.toLocaleString()} credits` +
        (discountNow > 0 ? ` (${discountNow.toLocaleString()} discount applied)` : "") +
        (result.server_uuid ? ". Your server is being set up." : "")
    );
    closeSubscribeFlow();
    await loadData();
    activeTab.value = "my-subscriptions";
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to subscribe");
  } finally {
    subscribing.value = false;
  }
};

const confirmCancelSub = (sub: Subscription) => { subToCancel.value = sub; showCancelConfirm.value = true; };
const executeCancelSub = async () => {
  if (!subToCancel.value) return;
  try {
    await cancelSubscription(subToCancel.value.id);
    toast.success("Subscription cancelled.");
    showCancelConfirm.value = false; subToCancel.value = null;
    await loadData();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to cancel subscription");
  }
};

const candidatePlansForSub = computed<Plan[]>(() => {
  const sub = subToChange.value;
  if (!sub) return [];
  const allowedUp = Array.isArray(sub.allowed_upgrade_plan_ids) ? sub.allowed_upgrade_plan_ids : [];
  const allowedDown = Array.isArray(sub.allowed_downgrade_plan_ids) ? sub.allowed_downgrade_plan_ids : [];
  const hasExplicitRules = allowedUp.length > 0 || allowedDown.length > 0;

  return plans.value.filter((p: Plan) => {
    if (p.id === sub.plan_id || p.is_active !== 1) return false;
    if (!hasExplicitRules) return true;
    const delta = (p.total_credits ?? p.price_credits) - sub.price_credits;
    if (delta > 0) return allowedUp.includes(p.id);
    if (delta < 0) return allowedDown.includes(p.id);
    return allowedUp.includes(p.id) || allowedDown.includes(p.id);
  });
});

const selectedTargetPlan = computed<Plan | null>(() => {
  if (targetPlanId.value == null) return null;
  return candidatePlansForSub.value.find((p) => p.id === targetPlanId.value) ?? null;
});

const changeDelta = computed<number>(() => {
  const sub = subToChange.value;
  const target = selectedTargetPlan.value;
  if (!sub || !target) return 0;
  return (target.total_credits ?? target.price_credits) - sub.price_credits;
});

const confirmChangePlan = (sub: Subscription) => {
  subToChange.value = sub;
  const first = candidatePlansForSub.value[0];
  targetPlanId.value = first ? first.id : null;
  showChangePlanConfirm.value = true;
};

const executeChangePlan = async () => {
  if (!subToChange.value || targetPlanId.value == null) return;
  changingPlan.value = true;
  try {
    const result = await changeSubscriptionPlan(subToChange.value.id, targetPlanId.value);
    userCredits.value = result.new_credits_balance;
    const delta = result.credits_delta;
    if (delta > 0) {
      toast.success(`Plan upgraded. Charged ${delta.toLocaleString()} credits.`);
    } else if (delta < 0) {
      toast.success(`Plan downgraded. Refunded ${Math.abs(delta).toLocaleString()} credits.`);
    } else {
      toast.success("Plan changed successfully.");
    }
    showChangePlanConfirm.value = false;
    subToChange.value = null;
    targetPlanId.value = null;
    await loadData();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to change plan");
  } finally {
    changingPlan.value = false;
  }
};

const toggleExpand = (planId: number) => {
  expandedPlanId.value = expandedPlanId.value === planId ? null : planId;
};

const activeSubscriptions = computed(() =>
  subscriptions.value.filter((s: Subscription) => s.status === "active" || s.status === "suspended")
);
const pastSubscriptions = computed(() =>
  subscriptions.value.filter((s: Subscription) => s.status === "cancelled" || s.status === "expired")
);
const balanceAfter = computed(() =>
  planToSubscribe.value ? userCredits.value - liveTotalCredits.value : 0
);

onMounted(async () => {
  await loadData();
  openPlanFromUrl();
});
</script>

<template>
  <div class="w-full h-full overflow-auto min-h-screen">

    <div v-if="shellView === 'subscribe' && planToSubscribe" class="container mx-auto max-w-4xl px-4 md:px-8 py-6">
      <div class="flex items-center gap-3 mb-6">
        <button
          type="button"
          @click="closeSubscribeFlow"
          class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          <ArrowLeft class="h-4 w-4" />Back to plans
        </button>
        <span class="text-muted-foreground/40">/</span>
        <h1 class="text-base font-semibold text-foreground truncate">Subscribe</h1>
      </div>

      <div class="mb-6">
        <h2 class="text-2xl font-bold tracking-tight text-foreground">{{ planToSubscribe.name }}</h2>
        <p class="text-sm text-muted-foreground mt-0.5">Confirm nest, server type, and name — same layout as the panel admin billing area.</p>
      </div>

      <div class="space-y-5">
        <!-- Dynamic resource configuration panel -->
        <div v-if="planToSubscribe.has_server_template" class="bg-card border border-border rounded-xl shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-border bg-muted/30 flex items-center justify-between">
            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Configure Server Resources</p>
            <span class="text-xs text-muted-foreground font-medium">Customize to match your needs</span>
          </div>
          <div class="p-5 space-y-5">
            <div v-for="res in RESOURCES_LIST" :key="res.key" class="space-y-2">
              <!-- If slider enabled for this resource -->
              <div v-if="planToSubscribe.slider_config?.[res.key]?.enabled" class="space-y-2 border-b border-border/50 pb-4 last:border-0 last:pb-0">
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <component :is="res.icon" :class="['h-4 w-4', res.iconColor]" />
                    <span class="text-sm font-semibold text-foreground">{{ res.name }}</span>
                    <span class="text-xs text-muted-foreground">(Base: {{ res.key === 'memory' || res.key === 'disk' ? fmtMB(planToSubscribe.slider_config[res.key].base) : planToSubscribe.slider_config[res.key].base + res.unit }})</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold text-primary">
                      {{ res.key === 'memory' || res.key === 'disk' ? fmtMB(customResources[res.key]) : customResources[res.key] + res.unit }}
                    </span>
                    <input
                      type="number"
                      :value="customResources[res.key]"
                      @change="handleCustomResourceInput(res.key, ($event.target as HTMLInputElement).value)"
                      class="w-20 h-7 text-xs text-center font-semibold border border-input rounded bg-background focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                  </div>
                </div>

                <div class="flex items-center gap-4">
                  <input
                    type="range"
                    :min="planToSubscribe.slider_config[res.key].base"
                    :max="planToSubscribe.slider_config[res.key].max"
                    :step="planToSubscribe.slider_config[res.key].step"
                    :value="customResources[res.key]"
                    @input="handleCustomResourceInput(res.key, ($event.target as HTMLInputElement).value)"
                    class="flex-1 h-1.5 rounded bg-muted appearance-none cursor-pointer accent-primary focus:outline-none"
                  />
                </div>

                <!-- Pricing detail for this resource -->
                <div class="flex justify-between items-center text-[11px] text-muted-foreground">
                  <span>Range: {{ res.key === 'memory' || res.key === 'disk' ? fmtMB(planToSubscribe.slider_config[res.key].base) : planToSubscribe.slider_config[res.key].base }} - {{ res.key === 'memory' || res.key === 'disk' ? fmtMB(planToSubscribe.slider_config[res.key].max) : planToSubscribe.slider_config[res.key].max }}</span>
                  <span v-if="customResources[res.key] > planToSubscribe.slider_config[res.key].base" class="text-amber-500 font-medium">
                    +{{ (Math.round((customResources[res.key] - planToSubscribe.slider_config[res.key].base) / planToSubscribe.slider_config[res.key].step) * planToSubscribe.slider_config[res.key].cost_per_step).toLocaleString() }} credits
                  </span>
                  <span v-else class="text-emerald-500 font-medium">Included in base price</span>
                </div>
              </div>

              <!-- If fixed mode (display as tag, no slider) -->
              <div v-else class="flex items-center justify-between text-xs py-1.5 border-b border-border/20 last:border-0">
                <div class="flex items-center gap-2">
                  <component :is="res.icon" :class="['h-3.5 w-3.5', res.iconColor]" />
                  <span class="text-muted-foreground">{{ res.name }}</span>
                </div>
                <span class="font-bold text-foreground">
                  {{ res.key === 'memory' || res.key === 'disk' ? fmtMB((planToSubscribe as any)[res.key]) : ((planToSubscribe as any)[res.key] != null ? (planToSubscribe as any)[res.key] : 'Unlimited') }}{{ res.unit }}
                  <span class="text-[10px] text-muted-foreground font-normal ml-1">(Fixed)</span>
                </span>
              </div>
            </div>
          </div>
        </div>

        <div
          v-if="planToSubscribe.user_can_choose_realm && planToSubscribe.allowed_realms_options?.length"
          class="bg-card border border-border rounded-xl shadow-sm p-5 space-y-3"
        >
          <div>
            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Nest <span class="text-red-400">*</span></label>
            <p class="text-xs text-muted-foreground mb-2">Game category for your server.</p>
            <div class="billing-select-wrap">
              <select
                v-model="chosenRealmId"
                class="billing-select"
              >
                <option disabled :value="null">Select a nest…</option>
                <option v-for="r in planToSubscribe.allowed_realms_options" :key="r.id" :value="r.id">
                  {{ r.name }}
                </option>
              </select>
              <ChevronDown class="billing-select-icon" />
            </div>
          </div>
        </div>

        <div
          v-if="planToSubscribe.user_can_choose_spell && planToSubscribe.allowed_spells_options?.length"
          class="bg-card border border-border rounded-xl shadow-sm p-5 space-y-3"
        >
          <div>
            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Egg (server type) <span class="text-red-400">*</span></label>
            <p class="text-xs text-muted-foreground mb-2">
              <template v-if="planToSubscribe.user_can_choose_realm && chosenRealmId == null">Select a nest first.</template>
              <template v-else-if="subscribeFilteredSpells.length === 0">No eggs for this nest — ask an admin to allow eggs for this nest.</template>
              <template v-else>Only eggs for your selected nest are listed.</template>
            </p>
            <div class="billing-select-wrap">
              <select
                v-model="chosenSpellId"
                :disabled="subscribeFilteredSpells.length === 0"
                class="billing-select"
              >
                <option disabled :value="null">
                  {{ subscribeFilteredSpells.length ? "Choose an egg…" : "No eggs for this nest" }}
                </option>
                <option v-for="s in subscribeFilteredSpells" :key="s.id" :value="s.id">
                  {{ s.name }}
                </option>
              </select>
              <ChevronDown class="billing-select-icon" />
            </div>
          </div>
        </div>

        <div v-if="planToSubscribe.has_server_template" class="bg-card border border-border rounded-xl shadow-sm p-5 space-y-3">
          <div>
            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Server name</label>
            <input
              v-model="serverName"
              type="text"
              placeholder="e.g. My Minecraft server"
              maxlength="100"
              class="flex h-9 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            />
            <p class="text-xs text-muted-foreground mt-1.5">Shown in your server list.</p>
          </div>
        </div>

        <div class="bg-card border border-border rounded-xl shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-border bg-muted/30">
            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Payment</p>
          </div>
          <div class="divide-y divide-border">
            <div class="flex justify-between items-center px-5 py-3 gap-4 text-sm">
              <span class="text-muted-foreground">Billing cycle</span>
              <span class="font-medium text-foreground">{{ getPeriodLabel(planToSubscribe.billing_period_days) }}</span>
            </div>
            <div class="flex justify-between items-center px-5 py-3 gap-4 text-sm">
              <span class="text-muted-foreground">Due now</span>
              <span class="text-base font-bold text-foreground tabular-nums text-primary">
                {{ liveTotalCredits.toLocaleString() }} <span class="text-sm font-normal text-muted-foreground">credits</span>
              </span>
            </div>
            <div class="px-5 py-3 text-xs text-muted-foreground">
              <label class="block text-xs font-medium text-muted-foreground mb-1.5">Coupon code (optional)</label>
              <input
                v-model="couponCode"
                type="text"
                placeholder="Enter coupon code"
                class="flex h-9 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm uppercase placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              />
              <p class="mt-1">Supports first-purchase and renewal coupons if configured by admins.</p>
            </div>
            <div class="px-5 py-3 text-xs text-muted-foreground space-y-1">
              <div>Base plan price: {{ (planToSubscribe.price_credits).toLocaleString() }} credits</div>
              <div v-if="sliderAdditionalCredits > 0" class="flex items-center justify-between cursor-pointer hover:text-foreground transition-colors" @click="showCostBreakdown = !showCostBreakdown">
                <span class="text-amber-500">Resource customizations: +{{ sliderAdditionalCredits.toLocaleString() }} credits</span>
                <ChevronDown :class="['h-3.5 w-3.5 transition-transform', showCostBreakdown ? 'rotate-180' : '']" />
              </div>
              <div v-if="showCostBreakdown && resourceCostBreakdown.length > 0" class="pl-4 space-y-1 text-[11px] border-l-2 border-amber-500/30 ml-2">
                <div v-for="item in resourceCostBreakdown" :key="item.name" class="flex justify-between">
                  <span>{{ item.name }} ({{ item.value }}):</span>
                  <span class="text-amber-500">+{{ item.extraCredits.toLocaleString() }} credits</span>
                </div>
              </div>
              <div v-if="(planToSubscribe.tax_rate_percent ?? 0) > 0">Tax ({{ planToSubscribe.tax_rate_percent }}%): +{{ Math.round((planToSubscribe.price_credits + sliderAdditionalCredits) * ((planToSubscribe.tax_rate_percent ?? 0) / 100)).toLocaleString() }} credits</div>
              <div v-if="(planToSubscribe.extra_charge_percent ?? 0) > 0">{{ planToSubscribe.extra_charge_name || 'Additional charge' }} ({{ planToSubscribe.extra_charge_percent }}%): +{{ Math.round((planToSubscribe.price_credits + sliderAdditionalCredits) * ((planToSubscribe.extra_charge_percent ?? 0) / 100)).toLocaleString() }} credits</div>
            </div>
            <div class="flex justify-between items-center px-5 py-3 gap-4 text-sm bg-muted/20">
              <span class="text-muted-foreground">Balance after</span>
              <span
                :class="[
                  'font-semibold tabular-nums',
                  balanceAfter < 0 ? 'text-red-400' : 'text-emerald-400',
                ]"
              >
                {{ balanceAfter.toLocaleString() }} credits
              </span>
            </div>
          </div>
        </div>

        <p class="text-xs text-muted-foreground leading-relaxed px-0.5">
          Renews every {{ getPeriodLabel(planToSubscribe.billing_period_days).toLowerCase() }}. If you lack credits at renewal, your subscription may be suspended per host policy.
        </p>
      </div>

      <div class="flex items-center justify-between gap-3 mt-8 pt-6 border-t border-border">
        <button
          type="button"
          @click="closeSubscribeFlow"
          class="rounded-lg border border-border bg-card px-4 py-2.5 text-sm font-medium hover:bg-accent transition-colors"
        >
          Cancel
        </button>
        <button
          type="button"
          @click="executeSubscribe"
          :disabled="subscribing || !canConfirmSubscribe"
          class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-6 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 disabled:pointer-events-none transition-colors shadow-sm"
        >
          <Loader2 v-if="subscribing" class="h-4 w-4 animate-spin" />
          <ShoppingCart v-else class="h-4 w-4" />
          Confirm and pay
        </button>
      </div>
    </div>

    <div v-else class="container mx-auto max-w-7xl px-4 md:px-8 py-6">

      <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2 text-foreground">
            <CreditCard class="h-6 w-6 text-primary" />Billing Plans
          </h1>
          <p class="text-sm text-muted-foreground mt-0.5">Subscribe to server plans billed in credits — same look as the admin billing tools.</p>
        </div>
        <button
          type="button"
          @click="loadData"
          class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-sm hover:bg-accent transition-colors"
        >
          <Loader2 v-if="plansLoading || subsLoading" class="h-3.5 w-3.5 animate-spin text-muted-foreground" />
          <RefreshCw v-else class="h-3.5 w-3.5 text-muted-foreground" />
          Refresh
        </button>
      </div>


      <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-card border border-border rounded-xl p-4 shadow-sm">
          <div class="flex items-center justify-between mb-2">
            <p class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Balance</p>
            <div class="bg-primary/10 rounded-lg p-1.5"><CreditCard class="h-3.5 w-3.5 text-primary" /></div>
          </div>
          <p class="text-2xl font-bold text-foreground tabular-nums">{{ userCredits.toLocaleString() }}</p>
          <p class="text-xs text-muted-foreground mt-0.5">credits available</p>
        </div>
        <div class="bg-card border border-border rounded-xl p-4 shadow-sm">
          <div class="flex items-center justify-between mb-2">
            <p class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Active</p>
            <div class="bg-emerald-500/10 rounded-lg p-1.5"><CheckCircle2 class="h-3.5 w-3.5 text-emerald-500" /></div>
          </div>
          <p class="text-2xl font-bold text-foreground">{{ activeSubscriptions.length }}</p>
          <p class="text-xs text-muted-foreground mt-0.5">subscriptions</p>
        </div>
        <div class="hidden sm:block bg-card border border-border rounded-xl p-4 shadow-sm col-span-2 sm:col-span-1">
          <div class="flex items-center justify-between mb-2">
            <p class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Plans</p>
            <div class="bg-blue-500/10 rounded-lg p-1.5"><BarChart3 class="h-3.5 w-3.5 text-blue-500" /></div>
          </div>
          <p class="text-2xl font-bold text-foreground">{{ plans.length }}</p>
          <p class="text-xs text-muted-foreground mt-0.5">available to you</p>
        </div>
      </div>


      <div class="flex gap-1 mb-5 bg-muted/50 rounded-xl p-1 w-fit flex-wrap">
        <button
          type="button"
          @click="activeTab = 'browse'"
          :class="['inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition-all', activeTab === 'browse' ? 'bg-card text-foreground shadow-sm border border-border' : 'text-muted-foreground hover:text-foreground']"
        >
          <ShoppingCart class="h-4 w-4" />Browse plans
        </button>
        <button
          type="button"
          @click="activeTab = 'my-subscriptions'"
          :class="['inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition-all', activeTab === 'my-subscriptions' ? 'bg-card text-foreground shadow-sm border border-border' : 'text-muted-foreground hover:text-foreground']"
        >
          <Clock class="h-4 w-4" />My subscriptions
          <span
            v-if="activeSubscriptions.length > 0"
            class="inline-flex items-center justify-center min-w-5 h-5 px-1 rounded-full bg-primary text-[10px] font-semibold text-primary-foreground"
          >
            {{ activeSubscriptions.length }}
          </span>
        </button>
      </div>


      <div v-if="activeTab === 'browse'">
        <div class="flex items-center gap-3 mb-4 flex-wrap">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search plans..."
            class="flex h-9 rounded-lg border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring w-full md:w-64"
          />
          <div v-if="availableLocationIds.length > 0" class="billing-select-wrap w-full md:w-52">
            <select v-model="activeLocationId" class="billing-select">
              <option :value="null">All locations</option>
              <option v-for="location in locationOptions" :key="location.id" :value="location.id">
                {{ location.label }}
              </option>
            </select>
            <ChevronDown class="billing-select-icon" />
          </div>
        </div>

        <div v-if="categories.length > 0" class="flex gap-2 mb-5 flex-wrap">
          <button
            @click="activeCategoryId = null"
            :class="['inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full text-sm font-medium border transition-all',
              activeCategoryId === null ? 'bg-foreground text-background border-foreground' : 'border-border text-muted-foreground hover:text-foreground hover:border-foreground/30']">
            All <span class="opacity-60 text-xs">({{ plans.length }})</span>
          </button>
          <button
            v-for="cat in categories" :key="cat.id"
            @click="activeCategoryId = cat.id"
            :class="['inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full text-sm font-medium border transition-all',
              colorClasses(cat.color, activeCategoryId === cat.id)]">
                  <img v-if="isImageIcon(cat.icon)" :src="normalizeAssetUrl(cat.icon || '')" :alt="cat.name" class="h-4 w-4 rounded object-cover" />
                  <span v-else-if="cat.icon" class="text-base leading-none">{{ cat.icon }}</span>
            {{ cat.name }}
            <span class="opacity-60 text-xs">({{ cat.plan_count }})</span>
          </button>
        </div>

        <div v-if="plansLoading" class="flex justify-center py-20">
          <Loader2 class="h-8 w-8 animate-spin text-muted-foreground" />
        </div>

        <div v-else-if="filteredPlans.length === 0" class="text-center py-20 bg-card border border-border rounded-xl">
          <CreditCard class="h-12 w-12 mx-auto mb-3 opacity-20" />
          <p class="text-muted-foreground">{{ activeCategoryId ? 'No plans in this category.' : 'No plans are currently available.' }}</p>
          <button v-if="activeCategoryId" @click="activeCategoryId = null" class="mt-3 text-sm text-primary hover:underline">Show all plans</button>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
          <div
            v-for="plan in filteredPlans" :key="plan.id"
            class="bg-card border border-border rounded-xl shadow-sm flex flex-col overflow-hidden transition-all hover:shadow-md"
            :style="cardBackgroundStyle(plan)"
            :class="{
              'border-primary/50 shadow-primary/10': !plan.is_sold_out && plan.can_afford,
              'opacity-60': plan.is_sold_out,
            }"
          >

            <div class="p-5 flex-1">

              <div v-if="plan.category" class="mb-2">
                <span :class="['inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full border font-medium', colorClasses(plan.category.color)]">
                  <img v-if="isImageIcon(plan.category.icon)" :src="normalizeAssetUrl(plan.category.icon || '')" :alt="plan.category.name" class="h-3.5 w-3.5 rounded object-cover" />
                  <span v-else-if="plan.category.icon">{{ plan.category.icon }}</span>
                  {{ plan.category.name }}
                </span>
              </div>
              <div class="flex items-start justify-between gap-2 mb-1">
                <h3 class="font-semibold text-base text-foreground leading-tight">{{ plan.name }}</h3>
                <span v-if="plan.is_sold_out"
                  class="shrink-0 text-xs font-medium px-2 py-0.5 rounded-full bg-red-500/15 text-red-400 border border-red-500/30">
                  Sold Out
                </span>
                <span v-else-if="plan.slots_available != null && plan.slots_available <= 5"
                  class="shrink-0 text-xs font-medium px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/30">
                  {{ plan.slots_available }} left
                </span>
              </div>

              <p v-if="plan.description" class="text-sm text-muted-foreground mb-3 leading-relaxed">
                {{ plan.description }}
              </p>


              <div class="bg-muted/40 rounded-lg p-4 mb-4 text-center">
                <div class="flex items-baseline justify-center gap-1.5">
                  <span class="text-3xl font-bold text-foreground">{{ (plan.total_credits ?? plan.price_credits).toLocaleString() }}</span>
                  <span class="text-muted-foreground text-sm">credits</span>
                </div>
                <p v-if="(plan.tax_credits ?? 0) > 0 || (plan.extra_charge_credits ?? 0) > 0" class="text-[11px] text-muted-foreground mt-1">
                  Base {{ (plan.base_credits ?? plan.price_credits).toLocaleString() }} cr + extras
                </p>
                <div class="flex items-center justify-center gap-1.5 mt-1 text-muted-foreground text-xs">
                  <CalendarClock class="h-3.5 w-3.5" />
                  <span>Billed {{ getPeriodLabel(plan.billing_period_days) }}</span>
                </div>
              </div>


              <div v-if="plan.has_server_template" class="space-y-1.5 mb-3">
                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-2">What you get</p>
                <div class="grid grid-cols-2 gap-1.5">
                  <div class="flex items-center gap-2 bg-muted/30 rounded-lg px-2.5 py-2">
                    <MemoryStick class="h-3.5 w-3.5 text-blue-400 shrink-0" />
                    <div>
                      <p class="text-xs font-medium text-foreground">{{ fmtMB(plan.memory) }}</p>
                      <p class="text-[10px] text-muted-foreground">RAM</p>
                    </div>
                  </div>
                  <div class="flex items-center gap-2 bg-muted/30 rounded-lg px-2.5 py-2">
                    <Cpu class="h-3.5 w-3.5 text-green-400 shrink-0" />
                    <div>
                      <p class="text-xs font-medium text-foreground">{{ plan.cpu }}%</p>
                      <p class="text-[10px] text-muted-foreground">CPU</p>
                    </div>
                  </div>
                  <div class="flex items-center gap-2 bg-muted/30 rounded-lg px-2.5 py-2">
                    <HardDrive class="h-3.5 w-3.5 text-orange-400 shrink-0" />
                    <div>
                      <p class="text-xs font-medium text-foreground">{{ fmtMB(plan.disk) }}</p>
                      <p class="text-[10px] text-muted-foreground">Storage</p>
                    </div>
                  </div>
                  <div class="flex items-center gap-2 bg-muted/30 rounded-lg px-2.5 py-2">
                    <Database class="h-3.5 w-3.5 text-purple-400 shrink-0" />
                    <div>
                      <p class="text-xs font-medium text-foreground">{{ plan.database_limit }}</p>
                      <p class="text-[10px] text-muted-foreground">Databases</p>
                    </div>
                  </div>
                </div>


                <button @click="toggleExpand(plan.id)"
                  class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground transition-colors mt-1">
                  <ChevronDown v-if="expandedPlanId !== plan.id" class="h-3.5 w-3.5" />
                  <ChevronUp v-else class="h-3.5 w-3.5" />
                  {{ expandedPlanId === plan.id ? 'Less details' : 'More details' }}
                </button>


                <div v-if="expandedPlanId === plan.id" class="grid grid-cols-2 gap-1.5 mt-1">
                  <div class="flex items-center gap-2 bg-muted/30 rounded-lg px-2.5 py-2">
                    <Shield class="h-3.5 w-3.5 text-cyan-400 shrink-0" />
                    <div>
                      <p class="text-xs font-medium text-foreground">{{ plan.backup_limit }}</p>
                      <p class="text-[10px] text-muted-foreground">Backups</p>
                    </div>
                  </div>
                  <div class="flex items-center gap-2 bg-muted/30 rounded-lg px-2.5 py-2">
                    <Package class="h-3.5 w-3.5 text-pink-400 shrink-0" />
                    <div>
                      <p class="text-xs font-medium text-foreground">
                        <span v-if="plan.allocation_limit">{{ plan.allocation_limit }}</span>
                        <Infinity v-else class="h-3 w-3 inline" />
                      </p>
                      <p class="text-[10px] text-muted-foreground">Ports</p>
                    </div>
                  </div>
                </div>


                <div v-if="expandedPlanId === plan.id && plan.long_description"
                  class="mt-2 text-xs text-muted-foreground bg-muted/20 rounded-lg p-3 leading-relaxed whitespace-pre-line">
                  {{ plan.long_description }}
                </div>
              </div>


              <div v-else class="flex items-center gap-2 bg-muted/20 rounded-lg px-3 py-2 mb-3">
                <Server class="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                <p class="text-xs text-muted-foreground">Subscription only — no server auto-provisioned</p>
              </div>


              <div v-if="!plan.can_afford && !plan.is_sold_out"
                class="flex items-center gap-2 text-xs text-amber-500 bg-amber-500/10 rounded-lg px-3 py-2 mb-3">
                <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                <span>Need {{ ((plan.total_credits ?? plan.price_credits) - userCredits).toLocaleString() }} more credits</span>
              </div>
            </div>


            <div class="px-5 pb-5">
              <div class="flex items-center gap-2">
                <button
                  @click="startSubscribe(plan)"
                  :disabled="!plan.can_afford || !!plan.is_sold_out"
                  :class="[
                    'flex-1 inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-colors',
                    plan.can_afford && !plan.is_sold_out
                      ? 'bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm'
                      : 'bg-muted text-muted-foreground cursor-not-allowed',
                  ]"
                >
                  <ShoppingCart class="h-4 w-4" />
                  {{ plan.is_sold_out ? 'Sold Out' : !plan.can_afford ? 'Insufficient Credits' : 'Subscribe Now' }}
                </button>
                <button
                  type="button"
                  @click="copyPlanUrl(plan)"
                  class="inline-flex items-center justify-center rounded-lg border border-border bg-card px-3 py-2.5 text-xs font-medium text-muted-foreground hover:text-foreground hover:bg-accent transition-colors"
                  title="Copy plan link"
                >
                  Copy Link
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>


      <div v-if="activeTab === 'my-subscriptions'">
        <div v-if="subsLoading" class="flex justify-center py-20">
          <Loader2 class="h-8 w-8 animate-spin text-muted-foreground" />
        </div>

        <div v-else-if="subscriptions.length === 0" class="text-center py-20 bg-card border border-border rounded-xl">
          <Clock class="h-12 w-12 mx-auto mb-3 opacity-20" />
          <p class="font-medium text-muted-foreground">No subscriptions yet</p>
          <button @click="activeTab = 'browse'"
            class="mt-4 inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors">
            <ShoppingCart class="h-4 w-4" />Browse Plans
          </button>
        </div>

        <div v-else class="space-y-6">


          <div v-if="activeSubscriptions.length > 0">
            <h2 class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-3">Active Subscriptions</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div
                v-for="sub in activeSubscriptions" :key="sub.id"
                class="bg-card border rounded-xl shadow-sm overflow-hidden"
                :class="sub.status === 'suspended' ? 'border-amber-500/40' : 'border-emerald-500/20'"
              >
                <div class="p-5">
                  <div class="flex items-start justify-between gap-2 mb-3">
                    <div>
                      <h3 class="font-semibold text-foreground">{{ sub.plan_name }}</h3>
                      <p v-if="sub.plan_description" class="text-xs text-muted-foreground mt-0.5">{{ sub.plan_description }}</p>
                    </div>
                    <span :class="['shrink-0 px-2 py-0.5 rounded-full text-xs font-medium border', sub.status === 'active' ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-amber-500/15 text-amber-400 border-amber-500/30']">
                      {{ sub.status.charAt(0).toUpperCase() + sub.status.slice(1) }}
                    </span>
                  </div>

                  <div class="grid grid-cols-2 gap-2 mb-3">
                    <div class="bg-muted/30 rounded-lg px-3 py-2">
                      <p class="text-[10px] text-muted-foreground uppercase tracking-wide mb-0.5">Cost</p>
                      <p class="text-sm font-semibold text-foreground">{{ sub.price_credits.toLocaleString() }} <span class="text-xs font-normal text-muted-foreground">cr</span></p>
                      <p class="text-[10px] text-muted-foreground">per {{ getPeriodLabel(sub.billing_period_days).toLowerCase() }}</p>
                    </div>
                    <div class="bg-muted/30 rounded-lg px-3 py-2">
                      <p class="text-[10px] text-muted-foreground uppercase tracking-wide mb-0.5">Next Renewal</p>
                      <p class="text-xs font-semibold text-foreground">{{ daysUntil(sub.next_renewal_at) }}</p>
                      <p class="text-[10px] text-muted-foreground">{{ formatDate(sub.next_renewal_at) }}</p>
                    </div>
                  </div>


                  <div v-if="sub.server_uuid" class="flex items-center gap-2 bg-muted/20 rounded-lg px-3 py-2 mb-3">
                    <Server class="h-3.5 w-3.5 text-primary shrink-0" />
                    <div class="min-w-0">
                      <p class="text-[10px] text-muted-foreground uppercase tracking-wide">Server</p>
                      <p class="text-xs font-mono text-muted-foreground truncate">{{ sub.server_uuid }}</p>
                    </div>
                    <CheckCircle2 class="h-3.5 w-3.5 text-emerald-400 shrink-0 ml-auto" />
                  </div>

                  <div
                    v-if="Number(sub.admin_credits_refunded_total ?? 0) > 0"
                    class="flex items-start gap-2 text-xs text-violet-700 dark:text-violet-300 bg-violet-500/10 rounded-lg px-3 py-2 mb-3 border border-violet-500/20"
                  >
                    <CircleDollarSign class="h-3.5 w-3.5 shrink-0 mt-0.5" />
                    <span>
                      Staff added <strong>{{ Number(sub.admin_credits_refunded_total ?? 0).toLocaleString() }} cr</strong> to your balance for this subscription
                      <span v-if="sub.admin_refunded_at"> (latest {{ formatDate(sub.admin_refunded_at) }})</span>.
                    </span>
                  </div>


                  <div v-if="sub.status === 'suspended'"
                    class="flex items-start gap-2 text-xs text-amber-500 bg-amber-500/10 rounded-lg px-3 py-2 mb-3">
                    <PauseCircle class="h-3.5 w-3.5 shrink-0 mt-0.5" />
                    <span>Suspended — insufficient credits at renewal. Top up your balance and your server will be restored at the next billing cycle.</span>
                  </div>

                  <button
                    v-if="sub.status === 'active'"
                    @click="confirmCancelSub(sub)"
                    class="w-full inline-flex items-center justify-center gap-2 rounded-lg border border-red-500/30 bg-red-500/5 px-3 py-1.5 text-xs font-medium text-red-400 hover:bg-red-500/15 transition-colors"
                  >
                    <XCircle class="h-3.5 w-3.5" />Cancel Subscription
                  </button>
                  <button
                    v-if="sub.status === 'active' || sub.status === 'suspended'"
                    @click="confirmChangePlan(sub)"
                    class="w-full mt-2 inline-flex items-center justify-center gap-2 rounded-lg border border-primary/30 bg-primary/5 px-3 py-1.5 text-xs font-medium text-primary hover:bg-primary/10 transition-colors"
                  >
                    <RefreshCw class="h-3.5 w-3.5" />Change Plan
                  </button>
                </div>
              </div>
            </div>
          </div>


          <div v-if="pastSubscriptions.length > 0">
            <h2 class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-3">Past Subscriptions</h2>
            <div class="bg-card border border-border rounded-xl shadow-sm overflow-hidden">
              <table class="w-full text-sm">
                <thead>
                  <tr class="border-b border-border bg-muted/40">
                    <th class="text-left px-4 py-3 font-medium text-muted-foreground text-xs uppercase tracking-wide">Plan</th>
                    <th class="text-left px-4 py-3 font-medium text-muted-foreground text-xs uppercase tracking-wide hidden md:table-cell">Cost</th>
                    <th class="text-left px-4 py-3 font-medium text-muted-foreground text-xs uppercase tracking-wide">Status</th>
                    <th class="text-left px-4 py-3 font-medium text-muted-foreground text-xs uppercase tracking-wide hidden sm:table-cell">Ended</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border opacity-70">
                  <tr v-for="sub in pastSubscriptions" :key="sub.id" class="hover:opacity-90 transition-opacity">
                    <td class="px-4 py-3 font-medium">
                      {{ sub.plan_name }}
                      <span
                        v-if="Number(sub.admin_credits_refunded_total ?? 0) > 0"
                        class="mt-1 flex items-center gap-1 text-[10px] font-normal text-violet-600 dark:text-violet-400"
                      >
                        <CircleDollarSign class="h-3 w-3 shrink-0" />
                        +{{ Number(sub.admin_credits_refunded_total ?? 0).toLocaleString() }} cr from staff
                      </span>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground text-xs hidden md:table-cell">{{ sub.price_credits.toLocaleString() }} cr / {{ getPeriodLabel(sub.billing_period_days) }}</td>
                    <td class="px-4 py-3">
                      <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-red-500/15 text-red-400 border border-red-500/30">
                        {{ sub.status.charAt(0).toUpperCase() + sub.status.slice(1) }}
                      </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-muted-foreground hidden sm:table-cell">
                      {{ formatDate(sub.cancelled_at ?? sub.created_at) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

    </div>


    <Teleport to="body">
      <div v-if="showCancelConfirm && subToCancel" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" @click.self="showCancelConfirm = false">
        <div class="bg-card border border-border rounded-xl shadow-2xl w-full max-w-sm">
          <div class="px-6 py-4 border-b border-border"><h2 class="text-base font-semibold">Cancel Subscription?</h2></div>
          <div class="p-6">
            <p class="text-sm text-muted-foreground mb-5">
              Cancel your <strong class="text-foreground">{{ subToCancel.plan_name }}</strong> subscription?
              No refund will be issued.
              <span v-if="subToCancel.server_uuid" class="block mt-2 text-amber-500">
                Your server will be suspended when the subscription ends.
              </span>
            </p>
            <div class="flex gap-3">
              <button @click="showCancelConfirm = false" class="flex-1 rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-accent transition-colors">Keep It</button>
              <button @click="executeCancelSub" :disabled="subsLoading"
                class="flex-1 inline-flex items-center justify-center gap-2 rounded-lg bg-red-500 px-4 py-2 text-sm font-medium text-white hover:bg-red-600 disabled:opacity-60 transition-colors">
                <Loader2 v-if="subsLoading" class="h-4 w-4 animate-spin" />Yes, Cancel
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="showChangePlanConfirm && subToChange" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" @click.self="showChangePlanConfirm = false">
        <div class="bg-card border border-border rounded-xl shadow-2xl w-full max-w-lg">
          <div class="px-6 py-4 border-b border-border"><h2 class="text-base font-semibold">Change Subscription Plan</h2></div>
          <div class="p-6 space-y-4">
            <p class="text-sm text-muted-foreground">
              Current: <strong class="text-foreground">{{ subToChange.plan_name }}</strong>
            </p>
            <div class="billing-select-wrap">
              <select v-model="targetPlanId" class="billing-select" :disabled="candidatePlansForSub.length === 0">
                <option :value="null" disabled>Select new plan...</option>
                <option v-for="plan in candidatePlansForSub" :key="plan.id" :value="plan.id">
                  {{ plan.name }} — {{ (plan.total_credits ?? plan.price_credits).toLocaleString() }} credits / {{ getPeriodLabel(plan.billing_period_days) }}
                </option>
              </select>
              <ChevronDown class="billing-select-icon" />
            </div>
            <p v-if="candidatePlansForSub.length === 0" class="text-xs text-muted-foreground">
              No plan changes are allowed for this subscription right now. Ask an admin.
            </p>
            <p v-if="selectedTargetPlan" class="text-xs text-muted-foreground">
              <span v-if="changeDelta > 0">You will pay <strong class="text-foreground">{{ changeDelta.toLocaleString() }} credits</strong> now.</span>
              <span v-else-if="changeDelta < 0">You will receive <strong class="text-foreground">{{ Math.abs(changeDelta).toLocaleString() }} credits</strong> now.</span>
              <span v-else>No immediate credit change.</span>
            </p>
            <div class="flex gap-3">
              <button @click="showChangePlanConfirm = false" class="flex-1 rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-accent transition-colors">Cancel</button>
              <button
                @click="executeChangePlan"
                :disabled="changingPlan || targetPlanId == null"
                class="flex-1 inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60 transition-colors"
              >
                <Loader2 v-if="changingPlan" class="h-4 w-4 animate-spin" />
                Confirm Change
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>
