import { useCallback, useEffect, useState } from 'react';
import { apiStaffAction, apiStaffDashboard, type StaffActionResult, type StaffDashboardData } from '../../api/client';

export type StaffNotice = { kind: 'ok' | 'err'; text: string };

export function useStaffDashboard() {
  const [data, setData] = useState<StaffDashboardData | null>(null);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<StaffNotice | null>(null);

  const load = useCallback(async () => {
    setState('loading');
    const response = await apiStaffDashboard();
    if (response.success && response.data) {
      setData(response.data);
      setState('ready');
      return;
    }
    setState('error');
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const runAction = useCallback(async (
    payload: Record<string, unknown>,
    successMessage = 'Action effectuée.',
  ): Promise<StaffActionResult | null> => {
    setBusy(true);
    const response = await apiStaffAction(payload);
    setBusy(false);
    if (!response.success || !response.data) {
      setNotice({ kind: 'err', text: response.error ?? 'Action refusée.' });
      return null;
    }
    setNotice({ kind: 'ok', text: response.data.message ?? successMessage });
    await load();
    return response.data;
  }, [load]);

  return { data, state, busy, notice, setNotice, load, runAction };
}
