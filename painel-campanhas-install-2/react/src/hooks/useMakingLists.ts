import { useQuery } from "@tanstack/react-query";
import { getMakingCostCenters, getMakingTeams } from "@/lib/api";

/**
 * Equipes Making (`team/list_api`) — JWT global. Só busca quando `enabled`.
 */
export function useMakingTeams(enabled: boolean) {
  return useQuery({
    queryKey: ["making-teams"],
    queryFn: getMakingTeams,
    enabled,
    staleTime: 60 * 1000,
  });
}

/**
 * Centros de custo Making (`cost/list_api`).
 */
export function useMakingCostCenters(enabled: boolean) {
  return useQuery({
    queryKey: ["making-cost-centers"],
    queryFn: getMakingCostCenters,
    enabled,
    staleTime: 60 * 1000,
  });
}
