import { useState, useEffect } from "react";
import { Plus, Trash2, Filter as FilterIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

export interface FilterItem {
    id: string;
    column: string;
    operator: string;
    value: any;
}

interface FilterBuilderProps {
    availableFilters: any[];
    filters: FilterItem[];
    onChange: (filters: FilterItem[]) => void;
}

const OPERATORS_TEXT = [
    { value: "equals", label: "Igual a" },
    { value: "not_equals", label: "Diferente de" },
    { value: "contains", label: "Contém" },
    { value: "not_contains", label: "Não contém" },
    { value: "starts_with", label: "Começa com" },
    { value: "ends_with", label: "Termina com" },
];

const OPERATORS_NUMBER = [
    { value: "equals", label: "Igual a" },
    { value: "not_equals", label: "Diferente de" },
    { value: "greater", label: "Maior que" },
    { value: "greater_equals", label: "Maior ou igual a" },
    { value: "less", label: "Menor que" },
    { value: "less_equals", label: "Menor ou igual a" },
];

const OPERATORS_SELECT = [
    { value: "equals", label: "Igual a" },
    { value: "not_equals", label: "Diferente de" },
    { value: "in", label: "Está na lista" },
    { value: "not_in", label: "Não está na lista" },
];

export function FilterBuilder({ availableFilters, filters, onChange }: FilterBuilderProps) {

    const addFilter = () => {
        const newFilter: FilterItem = {
            id: crypto.randomUUID(),
            column: "",
            operator: "",
            value: "",
        };
        onChange([...filters, newFilter]);
    };

    const removeFilter = (id: string) => {
        onChange(filters.filter((f) => f.id !== id));
    };

    const updateFilter = (id: string, field: keyof FilterItem, value: any) => {
        onChange(
            filters.map((f) => {
                if (f.id === id) {
                    const updated = { ...f, [field]: value };

                    // Reset operator and value when column changes
                    if (field === "column") {
                        updated.operator = "";
                        updated.value = "";
                    }

                    // Reset value when operator changes to something incompatible (optional, but good UX)
                    // For now we keep it simple

                    return updated;
                }
                return f;
            })
        );
    };

    const getOperators = (columnName: string) => {
        const filterDef = availableFilters.find((f) => (f.column || f.name || f) === columnName);
        if (!filterDef) return [];

        if (filterDef.type === "numeric" || filterDef.data_type === "int" || filterDef.data_type === "decimal") {
            return OPERATORS_NUMBER;
        }

        if (filterDef.type === "select" || filterDef.options) {
            return OPERATORS_SELECT;
        }

        return OPERATORS_TEXT;
    };

    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-base font-medium flex items-center gap-2">
                        <FilterIcon className="h-4 w-4" />
                        Filtros Avançados
                    </CardTitle>
                    <Button onClick={addFilter} variant="outline" size="sm" className="gap-2">
                        <Plus className="h-4 w-4" />
                        Adicionar Filtro
                    </Button>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {filters.length === 0 ? (
                    <div className="text-center py-8 text-muted-foreground text-sm border-2 border-dashed rounded-lg">
                        Nenhum filtro aplicado. Clique em "Adicionar Filtro" para começar.
                    </div>
                ) : (
                    <div className="space-y-3">
                        {filters.map((filter, index) => {
                            const filterDef = availableFilters.find(
                                (f) => (f.column || f.name || f) === filter.column
                            );
                            const operators = getOperators(filter.column);
                            const isSelect = filterDef?.type === "select" || filterDef?.options;

                            return (
                                <div key={filter.id} className="flex flex-col sm:flex-row gap-3 items-end sm:items-center p-3 border rounded-lg bg-muted/20">

                                    {/* Column Select */}
                                    <div className="w-full sm:w-1/3 space-y-1.5">
                                        <Label className="text-xs text-muted-foreground">Campo</Label>
                                        <Select
                                            value={filter.column}
                                            onValueChange={(v) => updateFilter(filter.id, "column", v)}
                                        >
                                            <SelectTrigger className="h-9">
                                                <SelectValue placeholder="Selecione..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {availableFilters.map((f, i) => {
                                                    const val = f.column || f.name || f;
                                                    const label = f.label || val;
                                                    return (
                                                        <SelectItem key={i} value={val}>
                                                            {label}
                                                        </SelectItem>
                                                    );
                                                })}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* Operator Select */}
                                    <div className="w-full sm:w-1/4 space-y-1.5">
                                        <Label className="text-xs text-muted-foreground">Operador</Label>
                                        <Select
                                            value={filter.operator}
                                            onValueChange={(v) => updateFilter(filter.id, "operator", v)}
                                            disabled={!filter.column}
                                        >
                                            <SelectTrigger className="h-9">
                                                <SelectValue placeholder="Operador..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {operators.map((op) => (
                                                    <SelectItem key={op.value} value={op.value}>
                                                        {op.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* Value Input */}
                                    <div className="w-full sm:w-1/3 space-y-1.5">
                                        <Label className="text-xs text-muted-foreground">Valor</Label>
                                        {isSelect && (filter.operator === 'equals' || filter.operator === 'not_equals') ? (
                                            <Select
                                                value={filter.value}
                                                onValueChange={(v) => updateFilter(filter.id, "value", v)}
                                            >
                                                <SelectTrigger className="h-9">
                                                    <SelectValue placeholder="Selecione..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {(filterDef.options || []).map((opt: any, i: number) => (
                                                        <SelectItem key={i} value={String(opt)}>
                                                            {String(opt)}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        ) : (filter.operator === 'in' || filter.operator === 'not_in') && isSelect ? (
                                            <div className="text-xs text-muted-foreground p-2 border rounded bg-background">
                                                Use vírgulas para múltiplos valores (ex: SP, RJ)
                                                <Input
                                                    value={filter.value}
                                                    onChange={(e) => updateFilter(filter.id, "value", e.target.value)}
                                                    className="h-8 mt-1"
                                                    placeholder="Valor 1, Valor 2..."
                                                />
                                            </div>
                                        ) : (
                                            <Input
                                                value={filter.value}
                                                onChange={(e) => updateFilter(filter.id, "value", e.target.value)}
                                                className="h-9"
                                                placeholder="Valor..."
                                                disabled={!filter.operator}
                                            />
                                        )}
                                    </div>

                                    {/* Remove Button */}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="h-9 w-9 text-muted-foreground hover:text-destructive"
                                        onClick={() => removeFilter(filter.id)}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
