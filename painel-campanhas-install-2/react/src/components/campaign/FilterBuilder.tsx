import { Plus, Trash2, Filter as FilterIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
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
    /** Esconder card externo (ex.: uso dentro de um Dialog já com título). */
    embedded?: boolean;
}

const OPERATORS_NULL = [
    { value: "is_null", label: "É nulo (IS NULL)" },
    { value: "is_not_null", label: "Não é nulo (IS NOT NULL)" },
];

const OPERATORS_TEXT = [
    { value: "equals", label: "Igual a" },
    { value: "not_equals", label: "Diferente de" },
    { value: "contains", label: "Contém" },
    { value: "not_contains", label: "Não contém" },
    { value: "starts_with", label: "Começa com" },
    { value: "ends_with", label: "Termina com" },
    ...OPERATORS_NULL,
];

const OPERATORS_NUMBER = [
    { value: "equals", label: "Igual a" },
    { value: "not_equals", label: "Diferente de" },
    { value: "greater", label: "Maior que" },
    { value: "greater_equals", label: "Maior ou igual a" },
    { value: "less", label: "Menor que" },
    { value: "less_equals", label: "Menor ou igual a" },
    ...OPERATORS_NULL,
];

const OPERATORS_SELECT = [
    { value: "equals", label: "Igual a (1 ou vários, OU)" },
    { value: "not_equals", label: "Diferente de (1 ou vários)" },
    { value: "in", label: "Está na lista" },
    { value: "not_in", label: "Não está na lista" },
    ...OPERATORS_NULL,
];

/** Igual a / Diferente de: sempre campo livre (vários valores = vírgula → IN no servidor). */
function EqualsNotEqualsValueEditor(props: {
    filterId: string;
    value: any;
    filterDef: any;
    onUpdate: (id: string, field: keyof FilterItem, value: any) => void;
}) {
    const { filterId, value, filterDef, onUpdate } = props;
    const str =
        value === null || value === undefined
            ? ""
            : Array.isArray(value)
              ? value.join(", ")
              : String(value);
    const options = (filterDef?.options || []) as unknown[];
    const hint =
        options.length > 0
            ? `Opções sugeridas: ${options
                  .slice(0, 16)
                  .map((o) => String(o))
                  .join(", ")}${options.length > 16 ? "…" : ""}`
            : "Digite um valor ou vários separados por vírgula (OR / IN no banco).";

    return (
        <div className="space-y-1.5 rounded-md border border-border bg-background p-2">
            <p className="text-[11px] leading-snug text-muted-foreground">{hint}</p>
            <Textarea
                value={str}
                onChange={(e) => onUpdate(filterId, "value", e.target.value)}
                rows={3}
                className="min-h-[72px] resize-y text-sm"
                placeholder="Ex.: Segmento A, Segmento B ou SP, RJ, MG"
                spellCheck={false}
            />
        </div>
    );
}

export function FilterBuilder({ availableFilters, filters, onChange, embedded = false }: FilterBuilderProps) {
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

                    if (field === "column") {
                        updated.operator = "";
                        updated.value = "";
                    }
                    if (field === "operator" && (value === "is_null" || value === "is_not_null")) {
                        updated.value = "";
                    }

                    return updated;
                }
                return f;
            }),
        );
    };

    const getOperators = (columnName: string) => {
        const filterDef = availableFilters.find((f) => (f.column || f.name || f) === columnName);
        if (!filterDef) return [];

        const dt = String(filterDef.data_type || filterDef.type || "")
            .toLowerCase()
            .trim();
        const NUMERIC_TYPES = [
            "int",
            "bigint",
            "tinyint",
            "smallint",
            "mediumint",
            "integer",
            "numeric",
            "decimal",
            "float",
            "double",
            "real",
        ];
        if (
            dt === "numeric" ||
            NUMERIC_TYPES.some((t) => dt === t || dt.startsWith(t + "(")) ||
            filterDef.type === "numeric"
        ) {
            return OPERATORS_NUMBER;
        }

        if (filterDef.type === "select" || filterDef.options) {
            return OPERATORS_SELECT;
        }

        return OPERATORS_TEXT;
    };

    const rows = (
        <>
            {filters.length === 0 ? (
                <div className="rounded-lg border-2 border-dashed py-8 text-center text-sm text-muted-foreground">
                    Nenhum filtro aplicado. Clique em &quot;Adicionar Filtro&quot; para começar.
                </div>
            ) : (
                <div className="space-y-3">
                    {filters.map((filter) => {
                        const filterDef = availableFilters.find(
                            (f) => (f.column || f.name || f) === filter.column,
                        );
                        const operators = getOperators(filter.column);
                        const isSelect = filterDef?.type === "select" || filterDef?.options;
                        const noValueOp = filter.operator === "is_null" || filter.operator === "is_not_null";
                        const isEqualsOrNot =
                            filter.operator === "equals" || filter.operator === "not_equals";
                        const isInList = filter.operator === "in" || filter.operator === "not_in";

                        return (
                            <div
                                key={filter.id}
                                className="flex flex-col gap-3 rounded-lg border bg-muted/20 p-3 sm:flex-row sm:items-end"
                            >
                                <div className="w-full space-y-1.5 sm:w-[28%]">
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

                                <div className="w-full space-y-1.5 sm:w-[24%]">
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

                                <div className="w-full min-w-0 flex-1 space-y-1.5">
                                    <Label className="text-xs text-muted-foreground">Valor</Label>
                                    {noValueOp ? (
                                        <p className="rounded-md border bg-muted/30 px-2 py-2 text-xs text-muted-foreground">
                                            Este operador não usa valor.
                                        </p>
                                    ) : isEqualsOrNot ? (
                                        <EqualsNotEqualsValueEditor
                                            filterId={filter.id}
                                            value={filter.value}
                                            filterDef={filterDef}
                                            onUpdate={updateFilter}
                                        />
                                    ) : isInList && isSelect ? (
                                        <div className="space-y-1.5 rounded-md border border-border bg-background p-2">
                                            <p className="text-[11px] text-muted-foreground">
                                                Um valor por linha ou separados por vírgula.
                                            </p>
                                            <Textarea
                                                value={
                                                    filter.value === null || filter.value === undefined
                                                        ? ""
                                                        : Array.isArray(filter.value)
                                                          ? filter.value.join(", ")
                                                          : String(filter.value)
                                                }
                                                onChange={(e) => updateFilter(filter.id, "value", e.target.value)}
                                                rows={2}
                                                className="min-h-[56px] resize-y text-sm"
                                                placeholder="Valor 1, Valor 2…"
                                                spellCheck={false}
                                            />
                                        </div>
                                    ) : (
                                        <Input
                                            value={
                                                filter.value === null || filter.value === undefined
                                                    ? ""
                                                    : String(filter.value)
                                            }
                                            onChange={(e) => updateFilter(filter.id, "value", e.target.value)}
                                            className="h-9"
                                            placeholder="Valor..."
                                            disabled={!filter.operator}
                                        />
                                    )}
                                </div>

                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-9 w-9 shrink-0 text-muted-foreground hover:text-destructive"
                                    onClick={() => removeFilter(filter.id)}
                                    type="button"
                                    aria-label="Remover filtro"
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </div>
                        );
                    })}
                </div>
            )}
        </>
    );

    if (embedded) {
        return (
            <div className="space-y-4">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-base font-medium">Filtros</h3>
                    <Button onClick={addFilter} variant="outline" size="sm" type="button" className="gap-2">
                        <Plus className="h-4 w-4" />
                        Adicionar
                    </Button>
                </div>
                {rows}
            </div>
        );
    }

    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <CardTitle className="flex items-center gap-2 text-base font-medium">
                        <FilterIcon className="h-4 w-4" />
                        Filtros Avançados
                    </CardTitle>
                    <Button onClick={addFilter} variant="outline" size="sm" type="button" className="gap-2">
                        <Plus className="h-4 w-4" />
                        Adicionar Filtro
                    </Button>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">{rows}</CardContent>
        </Card>
    );
}
