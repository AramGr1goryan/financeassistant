export interface ApiStatusResponse {
  status: string;
}

export interface MoneyResourceDto {
  minor_units: string;
  currency: string;
  currency_exponent: number;
}

export interface BalanceApiResponse {
  data: {
    owned_funds: MoneyResourceDto;
    pending_owned_funds_change: MoneyResourceDto;
    pending_owned_funds_debits: MoneyResourceDto;
    credit_liabilities: MoneyResourceDto;
    pending_credit_liability_change: MoneyResourceDto;
  };
}

export interface BudgetApiResponse {
  data: {
    available_funds: MoneyResourceDto;
    daily_limit: MoneyResourceDto;
    mandatory_payments: MoneyResourceDto;
    protected_reserve: MoneyResourceDto;
    allocated_savings: MoneyResourceDto;
    planning_days: number;
  };
}

export interface BalanceSummaryDto {
  currency: string;
  postedOwnedFundsFormatted: string;
  pendingOwnedFundsChangeFormatted: string;
  pendingOwnedFundsOutflowsFormatted: string;
  creditLiabilitiesFormatted: string;
  pendingCreditLiabilityChangeFormatted: string;
}

export interface BudgetSummaryDto {
  currency: string;
  planningDays: number;
  availableFundsFormatted: string;
  dailyLimitFormatted: string;
  mandatoryPaymentsFormatted: string;
  protectedReserveFormatted: string;
  allocatedSavingsFormatted: string;
  settings?: BudgetSettingsInput;
}

export interface BudgetSettingsInput {
  mandatory_payments: string;
  protected_reserve: string;
  allocated_savings: string;
  planning_days: number;
}

export interface AccountItemDto {
  id: string;
  name: string;
  type: "ASSET" | "CREDIT";
  currency: string;
  postedBalanceFormatted: string;
  pendingChangeFormatted: string;
  creditLiabilityFormatted: string;
  isActive: boolean;
}

export interface TransactionItemDto {
  id: string;
  accountName: string;
  type: "INCOME" | "EXPENSE" | "TRANSFER" | "REFUND" | "FEE" | "ADJUSTMENT";
  status: "PENDING" | "POSTED" | "REVERSED" | "DECLINED";
  amountFormatted: string;
  yerevanDate: string;
  description: string;
  merchant: string | null;
}

export interface IncomeExpectationDto {
  id: string;
  description: string;
  amountFormatted: string;
  expectedDate: string;
  status: "PLANNED" | "COMPLETED";
}

export interface DashboardWorkspacePayload {
  balance: BalanceSummaryDto;
  budget: BudgetSummaryDto | null;
  accounts: AccountItemDto[];
  recentTransactions: TransactionItemDto[];
  incomeExpectations: IncomeExpectationDto[];
}

export type WorkspaceConnectionState =
  | "empty"
  | "unauthenticated"
  | "unavailable"
  | "ready";

export interface WorkspaceOverviewResult {
  connectionState: WorkspaceConnectionState;
  apiHealthy: boolean;
  data: DashboardWorkspacePayload | null;
}

const DEFAULT_API_BASE_URL = "/api/v1";

export function resolveApiBaseUrl(rawUrl?: string): string {
  const candidate = (
    rawUrl ?? DEFAULT_API_BASE_URL
  )
    .trim()
    .replace(/\/+$/, "");

  if (!candidate.endsWith("/api/v1")) {
    throw new Error("API base URL must target the versioned /api/v1 boundary.");
  }

  return candidate;
}

export function formatMoneyResource(resource: MoneyResourceDto): string {
  const rawMinor = resource.minor_units.trim();

  if (!/^-?\d+$/.test(rawMinor)) {
    throw new Error("Money minor_units must be an integer string.");
  }

  const exponent = resource.currency_exponent;

  if (!Number.isInteger(exponent) || exponent < 0 || exponent > 6) {
    throw new Error("Money currency_exponent must be an integer between 0 and 6.");
  }

  const isNegative = rawMinor.startsWith("-");
  const digits = isNegative ? rawMinor.slice(1) : rawMinor;
  const padded = digits.padStart(exponent + 1, "0");
  const wholePart =
    exponent > 0 ? padded.slice(0, padded.length - exponent) : padded;
  const fractionPart =
    exponent > 0 ? padded.slice(padded.length - exponent) : "";
  const groupedWhole = wholePart.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  const sign = isNegative && BigInt(rawMinor) !== BigInt(0) ? "-" : "";

  return `${resource.currency} ${sign}${groupedWhole}${
    fractionPart ? `.${fractionPart}` : ""
  }`;
}

export async function fetchApiStatus(
  fetcher: typeof fetch = fetch,
  baseUrl?: string,
): Promise<boolean> {
  try {
    const resolvedBase = resolveApiBaseUrl(baseUrl);
    const response = await fetcher(`${resolvedBase}/status`, {
      method: "GET",
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    if (!response.ok) {
      return false;
    }

    const body = (await response.json()) as Partial<ApiStatusResponse>;
    return body.status === "ok";
  } catch {
    return false;
  }
}

export async function fetchWorkspaceOverview(
  fetcher: typeof fetch = fetch,
  baseUrl?: string,
  currency = "AMD",
  currencyExponent = 0,
): Promise<WorkspaceOverviewResult> {
  const apiHealthy = await fetchApiStatus(fetcher, baseUrl);

  if (!apiHealthy) {
    return {
      connectionState: "unavailable",
      apiHealthy: false,
      data: null,
    };
  }

  try {
    const resolvedBase = resolveApiBaseUrl(baseUrl);
    const query = `currency=${encodeURIComponent(currency)}&currency_exponent=${encodeURIComponent(String(currencyExponent))}`;
    const balanceResponse = await fetcher(`${resolvedBase}/balance?${query}`, {
      method: "GET",
      credentials: "include",
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    if (balanceResponse.status === 401 || balanceResponse.status === 403) {
      return {
        connectionState: "unauthenticated",
        apiHealthy: true,
        data: null,
      };
    }

    if (!balanceResponse.ok) {
      return {
        connectionState: "unavailable",
        apiHealthy: true,
        data: null,
      };
    }

    const balanceBody = (await balanceResponse.json()) as Partial<BalanceApiResponse>;
    if (!balanceBody.data?.owned_funds) {
      return {
        connectionState: "unavailable",
        apiHealthy: true,
        data: null,
      };
    }

    const balanceData = balanceBody.data;
    const balanceSummary: BalanceSummaryDto = {
      currency: balanceData.owned_funds.currency,
      postedOwnedFundsFormatted: formatMoneyResource(balanceData.owned_funds),
      pendingOwnedFundsChangeFormatted: formatMoneyResource(
        balanceData.pending_owned_funds_change,
      ),
      pendingOwnedFundsOutflowsFormatted: formatMoneyResource(
        balanceData.pending_owned_funds_debits,
      ),
      creditLiabilitiesFormatted: formatMoneyResource(
        balanceData.credit_liabilities,
      ),
      pendingCreditLiabilityChangeFormatted: formatMoneyResource(
        balanceData.pending_credit_liability_change,
      ),
    };

    let budgetSummary: BudgetSummaryDto | null = null;
    const budgetResponse = await fetcher(`${resolvedBase}/budget?${query}`, {
      method: "GET",
      credentials: "include",
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    if (budgetResponse.ok) {
      const budgetBody = (await budgetResponse.json()) as Partial<BudgetApiResponse>;
      if (budgetBody.data?.available_funds) {
        const b = budgetBody.data;
        budgetSummary = {
          currency: b.available_funds.currency,
          planningDays: b.planning_days,
          availableFundsFormatted: formatMoneyResource(b.available_funds),
          dailyLimitFormatted: formatMoneyResource(b.daily_limit),
          mandatoryPaymentsFormatted: formatMoneyResource(b.mandatory_payments),
          protectedReserveFormatted: formatMoneyResource(b.protected_reserve),
          allocatedSavingsFormatted: formatMoneyResource(b.allocated_savings),
          settings: {
            mandatory_payments: b.mandatory_payments.minor_units,
            protected_reserve: b.protected_reserve.minor_units,
            allocated_savings: b.allocated_savings.minor_units,
            planning_days: b.planning_days,
          },
        };
      }
      if (!budgetSummary) {
        throw new Error("Invalid budget response.");
      }
    } else if (budgetResponse.status === 401) {
      return { connectionState: "unauthenticated", apiHealthy: true, data: null };
    } else if (budgetResponse.status !== 404) {
      throw new Error(`Budget request failed (${budgetResponse.status}).`);
    }

    return {
      connectionState: "ready",
      apiHealthy: true,
      data: {
        balance: balanceSummary,
        budget: budgetSummary,
        accounts: [],
        recentTransactions: [],
        incomeExpectations: [],
      },
    };
  } catch {
    return {
      connectionState: "unavailable",
      apiHealthy,
      data: null,
    };
  }
}

export class SessionError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message);
  }
}

function csrfToken(cookie: string): string {
  const token = cookie.split("; ").find((item) => item.startsWith("XSRF-TOKEN="));
  if (!token) throw new Error("CSRF cookie is missing.");
  return decodeURIComponent(token.slice("XSRF-TOKEN=".length));
}

async function sessionRequest(
  method: "POST" | "DELETE",
  credentials?: { email: string; password: string },
  fetcher: typeof fetch = fetch,
  readCookie: () => string = () => document.cookie,
): Promise<void> {
  const token = await initializeCsrf(fetcher, readCookie);
  const response = await fetcher("/api/v1/session", {
    method,
    credentials: "same-origin",
    cache: "no-store",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-XSRF-TOKEN": token,
    },
    ...(credentials ? { body: JSON.stringify(credentials) } : {}),
  });
  if (!response.ok) throw new SessionError(response.status, "Session request failed.");
}

async function initializeCsrf(fetcher: typeof fetch, readCookie: () => string): Promise<string> {
  const csrf = await fetcher("/api/v1/session/csrf", {
    credentials: "same-origin",
    cache: "no-store",
    headers: { Accept: "application/json" },
  });
  if (!csrf.ok) throw new SessionError(csrf.status, "Unable to initialize the session.");
  return csrfToken(readCookie());
}

export async function saveBudgetSettings(
  settings: BudgetSettingsInput,
  fetcher: typeof fetch = fetch,
  readCookie: () => string = () => document.cookie,
): Promise<void> {
  const token = await initializeCsrf(fetcher, readCookie);
  const response = await fetcher("/api/v1/budget/settings", {
    method: "POST",
    credentials: "same-origin",
    cache: "no-store",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-XSRF-TOKEN": token,
    },
    body: JSON.stringify(settings),
  });
  if (!response.ok) throw new SessionError(response.status, "Budget settings could not be saved.");
}

export function signIn(email: string, password: string, fetcher?: typeof fetch, readCookie?: () => string): Promise<void> {
  return sessionRequest("POST", { email, password }, fetcher, readCookie);
}

export function signOut(fetcher?: typeof fetch, readCookie?: () => string): Promise<void> {
  return sessionRequest("DELETE", undefined, fetcher, readCookie);
}
