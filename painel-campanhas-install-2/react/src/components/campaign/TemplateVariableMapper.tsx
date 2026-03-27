import React from "react";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Database, Type } from "lucide-react";

// Fields available from the DB row for each contact
export const DB_FIELDS = [
    { value: "nome", label: "Nome do cliente" },
    { value: "cpf_cnpj", label: "CPF/CNPJ" },
    { value: "telefone", label: "Telefone" },
    { value: "idcob_contrato", label: "ID Contrato" },
    { value: "id_carteira", label: "ID Carteira" },
    { value: "idgis_ambiente", label: "ID GIS Ambiente" },
    { value: "data_cadastro", label: "Data de Cadastro" },
];

export type VarMapping = {
    type: "field" | "text";
    value: string;
};

interface Props {
    variables: string[];                                     // ["var1", "var2", ...]
    mapping: Record<string, VarMapping>;
    onChange: (mapping: Record<string, VarMapping>) => void;
}

export function TemplateVariableMapper({ variables, mapping, onChange }: Props) {
    if (!variables || variables.length === 0) return null;

    const update = (varName: string, patch: Partial<VarMapping>) => {
        const current = mapping[varName] ?? { type: "field", value: "nome" };
        onChange({ ...mapping, [varName]: { ...current, ...patch } });
    };

    return (
        <div className="space-y-3 rounded-lg border border-border p-4 bg-muted/20 animate-in fade-in">
            <div className="flex items-center gap-2 mb-1">
                <Database className="h-4 w-4 text-primary" />
                <span className="text-sm font-semibold">Mapeamento de Variáveis do Template</span>
            </div>
            <p className="text-xs text-muted-foreground mb-3">
                Defina o valor de cada variável do template. Pode usar um campo do banco de dados ou digitar um texto fixo.
            </p>

            <div className="space-y-2">
                {variables.map((varName) => {
                    const current = mapping[varName] ?? { type: "field", value: "nome" };
                    return (
                        <div key={varName} className="flex items-center gap-2 flex-wrap">
                            {/* Var label */}
                            <Badge variant="outline" className="font-mono text-[11px] shrink-0 min-w-[56px] justify-center">
                                {`{{${varName}}}`}
                            </Badge>

                            {/* Type toggle */}
                            <div className="flex rounded-md border border-border overflow-hidden shrink-0">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={current.type === "field" ? "default" : "ghost"}
                                    className="h-8 px-2 rounded-none text-xs gap-1"
                                    onClick={() => update(varName, { type: "field", value: "nome" })}
                                >
                                    <Database className="h-3 w-3" /> BD
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={current.type === "text" ? "default" : "ghost"}
                                    className="h-8 px-2 rounded-none text-xs gap-1"
                                    onClick={() => update(varName, { type: "text", value: "" })}
                                >
                                    <Type className="h-3 w-3" /> Texto
                                </Button>
                            </div>

                            {/* Value input depending on type */}
                            {current.type === "field" ? (
                                <Select value={current.value} onValueChange={(v) => update(varName, { value: v })}>
                                    <SelectTrigger className="h-8 text-xs flex-1 min-w-[160px]">
                                        <SelectValue placeholder="Escolha o campo..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {DB_FIELDS.map((f) => (
                                            <SelectItem key={f.value} value={f.value} className="text-xs">
                                                {f.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <Input
                                    className="h-8 text-xs flex-1 min-w-[160px]"
                                    placeholder={`Texto fixo para {{${varName}}}...`}
                                    value={current.value}
                                    onChange={(e) => update(varName, { value: e.target.value })}
                                />
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * Junta todo texto onde placeholders podem aparecer (RCS rich_card, WPP components/BODY, etc.).
 */
export function collectPlaceholdersSourceText(template: unknown): string {
    if (!template || typeof template !== "object") return "";
    const t = template as Record<string, any>;
    const raw = (t.raw_data && typeof t.raw_data === "object" ? t.raw_data : t) as Record<string, any>;

    const walkComponents = (components: unknown): string => {
        if (!Array.isArray(components)) return "";
        const chunks: string[] = [];
        for (const c of components) {
            if (!c || typeof c !== "object") continue;
            const comp = c as Record<string, any>;
            const typ = String(comp.type ?? comp.Type ?? "").toLowerCase();
            if (typeof comp.text === "string") chunks.push(comp.text);
            if (comp.body && typeof comp.body.text === "string") chunks.push(comp.body.text);
            if (typ === "body" || typ === "header" || typ === "footer") {
                if (typeof comp.text === "string") chunks.push(comp.text);
            }
            if (Array.isArray(comp.buttons)) {
                for (const b of comp.buttons) {
                    if (b && typeof b.text === "string") chunks.push(b.text);
                }
            }
            const ex = comp.example;
            if (ex && typeof ex === "object") {
                const bt = ex.body_text;
                if (Array.isArray(bt)) chunks.push(bt.join(" "));
                else if (typeof bt === "string") chunks.push(bt);
            }
            if (Array.isArray(comp.components)) chunks.push(walkComponents(comp.components));
        }
        return chunks.filter(Boolean).join("\n");
    };

    const parts: string[] = [];
    if (typeof t.content === "string") parts.push(t.content);
    if (typeof raw.content === "string") parts.push(raw.content);
    if (typeof raw.body === "string") parts.push(raw.body);
    if (typeof raw.template_body === "string") parts.push(raw.template_body);
    if (typeof raw.text === "string") parts.push(raw.text);
    if (typeof raw.description === "string") parts.push(raw.description);

    const rc = raw.rich_card ?? raw.richCard;
    if (rc && typeof rc === "object") {
        if (typeof rc.title === "string") parts.push(rc.title);
        if (typeof rc.description === "string") parts.push(rc.description);
        if (typeof rc.text === "string") parts.push(rc.text);
    }

    if (raw.components) parts.push(walkComponents(raw.components));
    const nested = raw.template;
    if (nested && typeof nested === "object" && Array.isArray(nested.components)) {
        parts.push(walkComponents(nested.components));
    }

    return parts.filter(Boolean).join("\n");
}

/**
 * Extracts variable names from a template string with {{varN}}, {n}, -var-, etc.
 * Returns sorted unique list (números na ordem natural: 1, 2, 10).
 */
export function extractVariables(text: string): string[] {
    if (!text) return [];
    // {{name}}, {name}, [name], -name- (Ótima bulk), var1
    const regex = /\{\{([\w.-]+)\}\}|\{([\w.-]+)\}|\[([\w.-]+)\]|-([\w.-]+)-|\b(var\d+)\b/g;
    const vars: string[] = [];
    let match;
    while ((match = regex.exec(text)) !== null) {
        const v = match[1] || match[2] || match[3] || match[4] || match[5];
        if (v) vars.push(v);
    }
    const uniq = [...new Set(vars)];
    const allNumeric = uniq.length > 0 && uniq.every((x) => /^\d+$/.test(x));
    if (allNumeric) {
        return uniq.sort((a, b) => parseInt(a, 10) - parseInt(b, 10));
    }
    return uniq.sort((a, b) => a.localeCompare(b));
}

/**
 * Resolves variable mapping against an example data row (for preview).
 */
export function resolveVariables(
    mapping: Record<string, VarMapping>,
    exampleRow?: Record<string, any>
): Record<string, string> {
    const resolved: Record<string, string> = {};
    for (const [varName, m] of Object.entries(mapping)) {
        if (m.type === "field") {
            resolved[varName] = exampleRow?.[m.value] ?? `[${m.value}]`;
        } else {
            resolved[varName] = m.value || `[${varName}]`;
        }
    }
    return resolved;
}
