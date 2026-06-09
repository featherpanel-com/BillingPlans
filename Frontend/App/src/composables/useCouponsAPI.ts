import { ref } from "vue";
import axios from "axios";
import type { AxiosError } from "axios";

export interface PlanCoupon {
  id: number;
  code: string;
  reward_type: string;
  plan_id: number | null;
  plan_name?: string | null;
  discount_percent: number | null;
  discount_credits: number | null;
  coupon_scope: "initial" | "renewal" | "both" | null;
  max_uses: number;
  uses: number;
  usage_count?: number;
  expires_at: string | null;
  is_valid?: boolean;
  created_at?: string;
}

export interface PlanCouponFormData {
  code: string;
  plan_id: number | null;
  discount_percent: number;
  discount_credits: number;
  coupon_scope: "initial" | "renewal" | "both";
  max_uses: number;
  expires_at: string;
}

export function useAdminCouponsAPI() {
  const loading = ref(false);

  const listCoupons = async (
    page = 1,
    limit = 20,
    search = "",
  ): Promise<{
    data: PlanCoupon[];
    total_pages: number;
    total: number;
  }> => {
    loading.value = true;
    try {
      const params = new URLSearchParams({
        page: String(page),
        limit: String(limit),
      });
      if (search.trim()) params.set("search", search.trim());
      const res = await axios.get(
        `/api/admin/billingplans/coupons?${params.toString()}`,
      );
      const pagination = res.data.data.meta?.pagination ?? {};
      return {
        data: res.data.data.data ?? [],
        total_pages: pagination.total_pages ?? 1,
        total: pagination.total ?? 0,
      };
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to load coupons");
    } finally {
      loading.value = false;
    }
  };

  const createCoupon = async (data: PlanCouponFormData): Promise<PlanCoupon> => {
    loading.value = true;
    try {
      const res = await axios.post("/api/admin/billingplans/coupons", {
        code: data.code.trim().toUpperCase(),
        plan_id: data.plan_id,
        discount_percent: data.discount_percent,
        discount_credits: data.discount_credits,
        coupon_scope: data.coupon_scope,
        max_uses: data.max_uses,
        expires_at: data.expires_at || null,
      });
      return res.data.data;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to create coupon");
    } finally {
      loading.value = false;
    }
  };

  const updateCoupon = async (
    id: number,
    data: Partial<PlanCouponFormData>,
  ): Promise<PlanCoupon> => {
    loading.value = true;
    try {
      const payload: Record<string, unknown> = { ...data };
      if (typeof payload.code === "string") {
        payload.code = payload.code.trim().toUpperCase();
      }
      if (payload.expires_at === "") payload.expires_at = null;
      const res = await axios.patch(
        `/api/admin/billingplans/coupons/${id}`,
        payload,
      );
      return res.data.data;
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to update coupon");
    } finally {
      loading.value = false;
    }
  };

  const deleteCoupon = async (id: number): Promise<void> => {
    loading.value = true;
    try {
      await axios.delete(`/api/admin/billingplans/coupons/${id}`);
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to delete coupon");
    } finally {
      loading.value = false;
    }
  };

  return { loading, listCoupons, createCoupon, updateCoupon, deleteCoupon };
}
