import { invoke } from '@tauri-apps/api/core';

export type User = {
  id: string;
  email: string | null;
  name: string;
  role: string;
  account_type: string;
};

export type Entitlement = {
  status: string;
  ends_at: string;
  data_used_gb: number;
  plan_name: string;
  period_label: string;
  traffic_gb: number;
  max_devices: number;
};

export type Device = {
  id: string;
  installation_id: string;
  name: string;
  platform: string;
  status: string;
  created_at: string;
};

export type AccountSnapshot = {
  user: User;
  entitlement: Entitlement | null;
  activeSubscriptions: number;
  nodeAuthorization: 'unavailable' | 'provisioning' | 'ready';
  devices: {
    entitlement_active: boolean;
    max_devices: number;
    registered_count: number;
    available_slots: number;
    devices: Device[];
  };
};

export type NativeBridge = {
  restore: () => Promise<AccountSnapshot | null>;
  login: (email: string, password: string) => Promise<AccountSnapshot>;
  logout: () => Promise<void>;
  localTestCredentials: () => Promise<{ email: string; password: string } | null>;
};

declare global {
  interface Window {
    __XLB_NATIVE_TEST__?: NativeBridge;
  }
}

export const native: NativeBridge = window.__XLB_NATIVE_TEST__ ?? {
  restore: () => invoke('restore_session'),
  login: (email, password) => invoke('login', { email, password }),
  logout: () => invoke('logout'),
  localTestCredentials: () => invoke('local_test_credentials'),
};
