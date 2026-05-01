import { ref } from "vue";
import axios from "axios";
import type { AxiosError } from "axios";

export interface AdminImage {
  id: number;
  name: string;
  url: string;
  created_at: string;
  updated_at: string;
}

export function useAdminImagesAPI() {
  const loading = ref(false);

  const listImages = async (
    page = 1,
    limit = 100,
    search = ""
  ): Promise<{ images: AdminImage[]; total_pages: number }> => {
    loading.value = true;
    try {
      const res = await axios.get("/api/admin/billingplans/images", { params: { page, limit, search } });
      return {
        images: res.data?.data?.images ?? [],
        total_pages: res.data?.data?.pagination?.total_pages ?? 1,
      };
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to load images");
    } finally {
      loading.value = false;
    }
  };

  const uploadImage = async (name: string, file: File): Promise<{ image_id: number; url: string; filename: string }> => {
    loading.value = true;
    try {
      const form = new FormData();
      form.append("name", name);
      form.append("image", file);
      const res = await axios.post("/api/admin/billingplans/images/upload", form);
      return {
        image_id: Number(res.data?.data?.image_id ?? 0),
        url: String(res.data?.data?.url ?? ""),
        filename: String(res.data?.data?.filename ?? ""),
      };
    } catch (e) {
      const err = e as AxiosError<{ message?: string }>;
      throw new Error(err.response?.data?.message || "Failed to upload image");
    } finally {
      loading.value = false;
    }
  };

  return { loading, listImages, uploadImage };
}
