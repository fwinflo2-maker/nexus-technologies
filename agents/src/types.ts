export interface TransferIntent {
  action: 'transfer';
  source_country: string;
  destination_country: string;
  amount: number;
  currency: string;
  destination_type: string;
  priority?: 'optimized' | 'cheapest' | 'fastest';
}

export interface RouteOption {
  route_id: string;
  provider: string;
  fees: number;
  estimated_delivery: string;
  reliability_score: number;
  received_amount: number;
  status: 'ELIGIBLE' | 'INELIGIBLE';
  compliance_status: 'APPROVED' | 'DECLINED' | 'REVIEW_REQUIRED';
  explanation: string;
}

export interface ComplianceResult {
  decision: 'APPROVED' | 'DECLINED' | 'REVIEW_REQUIRED';
  reason: string;
  checks: {
    kyc: boolean;
    aml: boolean;
    sanctions: boolean;
    limits: boolean;
    jurisdiction: boolean;
  };
}

export interface AgentResponse<T> {
  success: boolean;
  data?: T;
  error?: string;
  agent: string;
  timestamp: string;
}
