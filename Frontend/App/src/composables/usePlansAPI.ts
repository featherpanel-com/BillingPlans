import { ref } from "vue";
import axios from "axios";
import type { AxiosError } from "axios";

export interface Plan {
  id: number;
  category_id: number | null;
  category?: {
    id: number;
    name: string;
    icon: string | null;
    color: string | null;
  } | null;
  name: string;
  description: string | null;
  long_description: string | null;
  price_credits: number;
  base_credits?: number;
  tax_rate_percent?: number;
  tax_credits?: number;
  extra_charge_percent?: number;
  extra_charge_name?: string | null;
  extra_charge_credits?: number;
  total_credits?: number;
  billing_period_days: number;
  billing_period_label: string;
  is_active: number;
  server_config?: Record<string, unknown> | null;
  max_subscriptions: number | null;
  active_subscription_count?: number;
  slots_available?: number | null;
  is_sold_out?: boolean;

  node_ids?: number[]; // multi-node support
  node_id?: number | null; // legacy
  location_ids?: number[];
  location_names?: Record<string, string>;
  realms_id: number | null;
  spell_id: number | null;
  memory: number;
  cpu: number;
  disk: number;
  swap: number;
  io: number;
  backup_limit: number;
  database_limit: number;
  allocation_limit: number | null;
  startup_override: string | null;
  image_override: string | null;
  card_background_image: string | null;
  allowed_upgrade_plan_ids: number[];
  allowed_downgrade_plan_ids: number[];
  slider_config?: Record<
    string,
    {
      enabled: boolean;
      base: number;
      max: number;
      step: number;
      cost_per_step: number;
      rounding?: "nearest" | "up" | "down";
    }
  > | null;

  user_can_choose_realm: boolean;
  user_can_choose_spell: boolean;
  allowed_realms: number[];
  allowed_spells: number[];

  allowed_realms_options?: { id: number; name: string }[];
  allowed_spells_options?: { id: number; name: string; realm_id: number }[];
  has_server_template?: boolean;
  can_afford?: boolean;
  created_at: string;
  updated_at: string;
}

export interface PlanFormData {
  category_id: number | null;
  name: string;
  description: string | null;
  long_description: string | null;
  price_credits: number;
  tax_rate_percent: number;
  extra_charge_percent: number;
  extra_charge_name: string | null;
  billing_period_days: number;
  is_active: boolean;
  max_subscriptions: number | null;

  node_ids: number[]; // multi-node support
  realms_id: number | null;
  spell_id: number | null;
  memory: number;
  cpu: number;
  disk: number;
  swap: number;
  io: number;
  backup_limit: number;
  database_limit: number;
  allocation_limit: number | null;
  startup_override: string | null;
  image_override: string | null;
  card_background_image: string | null;
  slider_config?: Record<
    string,
    {
      enabled: boolean;
      base: number;
      max: number;
      step: number;
      cost_per_step: number;
      rounding?: "nearest" | "up" | "down";
    }
  > | null;

  user_can_choose_realm: boolean;
  user_can_choose_spell: boolean;
  allowed_realms: number[];
  allowed_spells: number[];
  allowed_upgrade_plan_ids: number[];
  allowed_downgrade_plan_ids: number[];
}

export interface PlanOption {
  id: number;
  name: string;
  realm_id?: number | null;
  location_id?: number | null;
  startup?: string | null;
  docker_image?: string | null;
}

export interface PlanOptions {
  plans: PlanOption[];
  nodes: PlanOption[];
  realms: PlanOption[];
  spells: PlanOption[];
  categories: Array<{
    id: number;
    name: string;
    icon: string | null;
    color: string | null;
    is_active: boolean;
  }>;
}

export function useAdminPlansAPI() {
  const loading = ref(false);

  const listPlans = async (
    page = 1,
    limit = 20,
    search = "",
  ): Promise<{ data: Plan[]; total: number; total_pages: number }> => {
    loading.value = true;
    try {
      const res = await axios.get("/api/admin/billingplans/plans", {
        params: { page, limit, search },
      });
      return {
        data: res.data.data.data ?? [],
        total: res.data.data.meta?.pagination?.total ?? 0,
        total_pages: res.data.data.meta?.pagination?.total_pages ?? 1,
      };
    } catch (e) {
      const err = e as AxiosError<{ message?: string; error_message?: string }>;
      throw new Error(
        err.response?.data?.message ||
          err.response?.data?.error_message ||
          "Failed to load plans",
      );
    } finally {
      loading.value = false;
    }
  };

  const getPlan = async (planId: number): Promise<Plan> => {
    loading.value = true;
    try {
      const res = await axios.get(`/api/admin/billingplans/plans/${planId}`);
      return res.data.data;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to load plan");
    } finally {
      loading.value = false;
    }
  };

  const getOptions = async (): Promise<PlanOptions> => {
    loading.value = true;
    try {
      const res = await axios.get("/api/admin/billingplans/options");
      return res.data.data;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to load options");
    } finally {
      loading.value = false;
    }
  };

  const createPlan = async (data: PlanFormData): Promise<Plan> => {
    loading.value = true;
    try {
      const res = await axios.post("/api/admin/billingplans/plans", data);
      return res.data.data;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to create plan");
    } finally {
      loading.value = false;
    }
  };

  const updatePlan = async (
    planId: number,
    data: Partial<PlanFormData>,
  ): Promise<Plan> => {
    loading.value = true;
    try {
      const res = await axios.patch(
        `/api/admin/billingplans/plans/${planId}`,
        data,
      );
      return res.data.data;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to update plan");
    } finally {
      loading.value = false;
    }
  };

  const deletePlan = async (planId: number): Promise<void> => {
    loading.value = true;
    try {
      await axios.delete(`/api/admin/billingplans/plans/${planId}`);
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to delete plan");
    } finally {
      loading.value = false;
    }
  };

  return {
    loading,
    listPlans,
    getPlan,
    getOptions,
    createPlan,
    updatePlan,
    deletePlan,
  };
}

export function useUserPlansAPI() {
  const loading = ref(false);

  const listPlans = async (): Promise<{
    data: Plan[];
    user_credits: number;
  }> => {
    loading.value = true;
    try {
      const res = await axios.get("/api/user/billingplans/plans");
      return {
        data: res.data.data.data ?? [],
        user_credits: res.data.data.user_credits ?? 0,
      };
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to load plans");
    } finally {
      loading.value = false;
    }
  };

  const subscribeToPlan = async (
    planId: number,
    options?: {
      server_name?: string;
      chosen_realm_id?: number | null;
      chosen_spell_id?: number | null;
      coupon_code?: string | null;
      custom_resources?: Record<string, number>;
    },
  ): Promise<{
    subscription: Record<string, unknown>;
    credits_deducted: number;
    credits_before_discount?: number;
    coupon?: Record<string, unknown> | null;
    base_credits?: number;
    tax_credits?: number;
    extra_charge_credits?: number;
    new_credits_balance: number;
    next_renewal_at: string;
    server_uuid: string | null;
  }> => {
    loading.value = true;
    try {
      const body: Record<string, unknown> = {};
      if (options?.server_name) body.server_name = options.server_name;
      if (options?.chosen_realm_id)
        body.chosen_realm_id = options.chosen_realm_id;
      if (options?.chosen_spell_id)
        body.chosen_spell_id = options.chosen_spell_id;
      if (options?.coupon_code) body.coupon_code = options.coupon_code;
      if (options?.custom_resources)
        body.custom_resources = options.custom_resources;
      const res = await axios.post(
        `/api/user/billingplans/plans/${planId}/subscribe`,
        body,
      );
      return res.data.data;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to subscribe");
    } finally {
      loading.value = false;
    }
  };

  const validateCoupon = async (
    planId: number,
    couponCode: string,
    customResources?: Record<string, number>,
  ): Promise<{
    valid: boolean;
    total_credits: number;
    charge_credits: number;
    discount_credits: number;
    coupon: Record<string, unknown>;
  }> => {
    loading.value = true;
    try {
      const body: Record<string, unknown> = {
        coupon_code: couponCode.trim().toUpperCase(),
      };
      if (customResources && Object.keys(customResources).length > 0) {
        body.custom_resources = customResources;
      }
      const res = await axios.post(
        `/api/user/billingplans/plans/${planId}/validate-coupon`,
        body,
      );
      return res.data.data;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Invalid coupon code");
    } finally {
      loading.value = false;
    }
  };

  return { loading, listPlans, subscribeToPlan, validateCoupon };
}
