import { ref } from "vue";
import axios from "axios";
import type { AxiosError } from "axios";

export interface Subscription {
  id: number;
  user_id: number;
  plan_id: number;
  server_uuid: string | null;
  status: "pending" | "active" | "suspended" | "cancelled" | "expired";
  next_renewal_at: string | null;
  suspended_at: string | null;
  cancelled_at: string | null;
  created_at: string;
  updated_at: string;
  plan_name: string;
  price_credits: number;
  billing_period_days: number;
  billing_period_label?: string;
  base_credits?: number;
  tax_credits?: number;
  extra_charge_credits?: number;
  total_credits?: number;
  allowed_upgrade_plan_ids?: number[];
  allowed_downgrade_plan_ids?: number[];
  plan_description?: string | null;
  
  username?: string;
  email?: string;
  user_uuid?: string;
  server_id?: number | null;
  server_name?: string | null;
    admin_credits_refunded_total?: number;
    admin_refunded_at?: string | null;
}

export interface ChangeSubscriptionPlanResult {
  subscription: Subscription;
  credits_delta: number;
  new_credits_balance: number;
  next_renewal_at: string;
}

export function useAdminSubscriptionsAPI() {
  const loading = ref(false);

  const listSubscriptions = async (
    page = 1,
    limit = 20,
    status = "",
    search = ""
  ): Promise<{
    data: Subscription[];
    total: number;
    total_pages: number;
    status_counts: Record<string, number>;
  }> => {
    loading.value = true;
    try {
      const res = await axios.get("/api/admin/billingplans/subscriptions", {
        params: { page, limit, status, search },
      });
      return {
        data: res.data.data.data ?? [],
        total: res.data.data.meta?.pagination?.total ?? 0,
        total_pages: res.data.data.meta?.pagination?.total_pages ?? 1,
        status_counts: res.data.data.meta?.status_counts ?? {},
      };
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(
        err.response?.data?.message || "Failed to load subscriptions"
      );
    } finally {
      loading.value = false;
    }
  };

  const getStats = async (): Promise<{
    subscriptions: Record<string, number>;
    total_plans: number;
    active_plans: number;
    admin_refunds?: {
      total_credits_refunded: number;
      subscriptions_with_refunds: number;
    };
  }> => {
    loading.value = true;
    try {
      const res = await axios.get("/api/admin/billingplans/stats");
      return res.data.data;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to load stats");
    } finally {
      loading.value = false;
    }
  };

  const updateSubscription = async (
    subscriptionId: number,
    data: {
      status?: string;
      server_uuid?: string | null;
      next_renewal_at?: string | null;
    }
  ): Promise<Subscription> => {
    loading.value = true;
    try {
      const res = await axios.patch(
        `/api/admin/billingplans/subscriptions/${subscriptionId}`,
        data
      );
      return res.data.data;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(
        err.response?.data?.message || "Failed to update subscription"
      );
    } finally {
      loading.value = false;
    }
  };

  const cancelSubscription = async (subscriptionId: number): Promise<void> => {
    loading.value = true;
    try {
      await axios.delete(
        `/api/admin/billingplans/subscriptions/${subscriptionId}`
      );
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(
        err.response?.data?.message || "Failed to cancel subscription"
      );
    } finally {
      loading.value = false;
    }
  };

  const refundSubscription = async (
    subscriptionId: number,
    amount: number
  ): Promise<{
    credits_refunded: number;
    user_credits_balance: number;
    admin_credits_refunded_total: number;
    admin_refunded_at: string | null;
    subscription?: Subscription;
  }> => {
    loading.value = true;
    try {
      const res = await axios.post(
        `/api/admin/billingplans/subscriptions/${subscriptionId}/refund`,
        { amount }
      );
      return res.data.data as {
        credits_refunded: number;
        user_credits_balance: number;
        admin_credits_refunded_total: number;
        admin_refunded_at: string | null;
        subscription?: Subscription;
      };
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to refund credits");
    } finally {
      loading.value = false;
    }
  };

  return {
    loading,
    listSubscriptions,
    getStats,
    updateSubscription,
    cancelSubscription,
    refundSubscription,
  };
}

export function useUserSubscriptionsAPI() {
  const loading = ref(false);

  const listSubscriptions = async (): Promise<{
    data: Subscription[];
    user_credits: number;
  }> => {
    loading.value = true;
    try {
      const res = await axios.get("/api/user/billingplans/subscriptions");
      return {
        data: res.data.data.data ?? [],
        user_credits: res.data.data.user_credits ?? 0,
      };
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(
        err.response?.data?.message || "Failed to load subscriptions"
      );
    } finally {
      loading.value = false;
    }
  };

  const cancelSubscription = async (subscriptionId: number): Promise<void> => {
    loading.value = true;
    try {
      await axios.delete(
        `/api/user/billingplans/subscriptions/${subscriptionId}`
      );
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(
        err.response?.data?.message || "Failed to cancel subscription"
      );
    } finally {
      loading.value = false;
    }
  };

  const changeSubscriptionPlan = async (
    subscriptionId: number,
    planId: number
  ): Promise<ChangeSubscriptionPlanResult> => {
    loading.value = true;
    try {
      const res = await axios.post(
        `/api/user/billingplans/subscriptions/${subscriptionId}/change-plan`,
        { plan_id: planId }
      );
      return res.data.data as ChangeSubscriptionPlanResult;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(
        err.response?.data?.message || "Failed to change subscription plan"
      );
    } finally {
      loading.value = false;
    }
  };

  return { loading, listSubscriptions, cancelSubscription, changeSubscriptionPlan };
}
