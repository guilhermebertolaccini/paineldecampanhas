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

/**
 * Ótima WPP HSM: `variable_sample` costuma vir como objeto `{"-var1-":"exemplo"}` ou, às vezes, string JSON.
 */
function parseOtimaWppVariableSample(template: unknown): Record<string, unknown> | null {
    if (!template || typeof template !== "object") return null;
    const t = template as Record<string, any>;
    if (t.source !== "otima_wpp") return null;
    let vs: unknown = t.variable_sample ?? t.variableSample;
    if (typeof vs === "string") {
        const s = vs.trim();
        if (!s) return null;
        try {
            vs = JSON.parse(s) as unknown;
        } catch {
            return null;
        }
    }
    if (!vs || typeof vs !== "object" || Array.isArray(vs)) return null;
    return vs as Record<string, unknown>;
}

/** Chaves na ordem retornada pela API (objeto ou JSON parseado) — ex.: `-var1-`, `-var2-`. */
export function listOtimaWppVariableKeysFromTemplate(template: unknown): string[] {
    const vs = parseOtimaWppVariableSample(template);
    if (!vs) return [];
    return Object.keys(vs);
}

export function buildInitialVariableMappingFromOtimaWpp(template: unknown): Record<string, VarMapping> | null {
    const vs = parseOtimaWppVariableSample(template);
    if (!vs) return null;
    const keys = Object.keys(vs);
    if (keys.length === 0) return null;
    const init: Record<string, VarMapping> = {};
    for (const k of keys) {
        init[k] = { type: "text", value: String(vs[k] ?? "") };
    }
    return init;
}

interface Props {
    variables: string[];                                     // ["var1", "var2", ...]
    mapping: Record<string, VarMapping>;
    onChange: (mapping: Record<string, VarMapping>) => void;
    /** Se definido, substitui {@link DB_FIELDS} no modo "campo" (ex.: cabeçalhos do CSV). */
    fieldOptions?: { value: string; label: string }[];
    /** Rótulo do botão de campo dinâmico (padrão: "BD" ou "CSV" quando `fieldOptions` vem do arquivo). */
    fieldSourceLabel?: string;
}

export function TemplateVariableMapper({
    variables,
    mapping,
    onChange,
    fieldOptions,
    fieldSourceLabel,
}: Props) {
    if (!variables || variables.length === 0) return null;

    const options = fieldOptions && fieldOptions.length > 0 ? fieldOptions : DB_FIELDS;
    const defaultFieldValue = options[0]?.value ?? "nome";
    const sourceBtnLabel = fieldSourceLabel ?? (fieldOptions && fieldOptions.length > 0 ? "CSV" : "BD");

    const update = (varName: string, patch: Partial<VarMapping>) => {
        const current = mapping[varName] ?? { type: "field", value: defaultFieldValue };
        onChange({ ...mapping, [varName]: { ...current, ...patch } });
    };

    return (
        <div className="space-y-3 rounded-lg border border-border p-4 bg-muted/20 animate-in fade-in">
            <div className="flex items-center gap-2 mb-1">
                <Database className="h-4 w-4 text-primary" />
                <span className="text-sm font-semibold">Mapeamento de Variáveis do Template</span>
            </div>
            <p className="text-xs text-muted-foreground mb-3">
                {fieldOptions && fieldOptions.length > 0
                    ? "Ligue cada variável do template a uma coluna do CSV ou a um texto fixo."
                    : "Defina o valor de cada variável do template. Pode usar um campo do banco de dados ou digitar um texto fixo."}
            </p>

            <div className="space-y-2">
                {variables.map((varName) => {
                    const current = mapping[varName] ?? { type: "field", value: defaultFieldValue };
                    const label =
                        varName.startsWith("-") && varName.endsWith("-")
                            ? varName
                            : `{{${varName}}}`;
                    return (
                        <div key={varName} className="flex items-center gap-2 flex-wrap">
                            {/* Var label */}
                            <Badge variant="outline" className="font-mono text-[11px] shrink-0 min-w-[56px] justify-center max-w-[140px] truncate" title={varName}>
                                {label}
                            </Badge>

                            {/* Type toggle */}
                            <div className="flex rounded-md border border-border overflow-hidden shrink-0">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={current.type === "field" ? "default" : "ghost"}
                                    className="h-8 px-2 rounded-none text-xs gap-1"
                                    onClick={() => update(varName, { type: "field", value: defaultFieldValue })}
                                >
                                    <Database className="h-3 w-3" /> {sourceBtnLabel}
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
                                        {options.map((f) => (
                                            <SelectItem key={f.value} value={f.value} className="text-xs">
                                                {f.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <Input
                                    className="h-8 text-xs flex-1 min-w-[160px]"
                                    placeholder={
                                        varName.startsWith("-") && varName.endsWith("-")
                                            ? `Texto fixo para ${varName}…`
                                            : `Texto fixo para {{${varName}}}…`
                                    }
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

    if (Array.isArray(raw.components)) parts.push(walkComponents(raw.components));
    if (Array.isArray(t.components) && t.components !== raw.components) {
        parts.push(walkComponents(t.components));
    }
    const nested = raw.template;
    if (nested && typeof nested === "object" && Array.isArray(nested.components)) {
        parts.push(walkComponents(nested.components));
    }
    // NOAH / Cloud API: corpo às vezes vem em `body.text` sem array `components`
    if (raw.body && typeof raw.body === "object" && typeof raw.body.text === "string") {
        parts.push(raw.body.text);
    }
    if (t.body && typeof t.body === "object" && typeof t.body.text === "string") {
        parts.push(t.body.text);
    }

    return parts.filter(Boolean).join("\n");
}

/** Placeholders `{{1}}`, `{{ 2 }}` (apenas índices numéricos), ordenados 1..N. */
export function extractNoahNumericPlaceholderKeys(text: string): string[] {
    if (!text) return [];
    const re = /\{\{\s*(\d+)\s*\}\}/g;
    const seen = new Set<number>();
    let m: RegExpExecArray | null;
    while ((m = re.exec(text)) !== null) {
        const d = parseInt(m[1], 10);
        if (!Number.isNaN(d) && d > 0) seen.add(d);
    }
    if (seen.size === 0) return [];
    return [...seen].sort((a, b) => a - b).map(String);
}

/** Conta parâmetros do BODY ({{n}} ou example.body_text) — Cloud API / NOAH. */
function inferNoahParameterCountFromComponents(components: unknown): number {
    if (!Array.isArray(components)) return 0;
    let fromPlaceholders = 0;
    let fromExample = 0;
    for (const c of components) {
        if (!c || typeof c !== "object") continue;
        const comp = c as Record<string, any>;
        const typ = String(comp.type ?? comp.Type ?? "").toLowerCase();
        if (typ !== "body") continue;
        const txt =
            typeof comp.text === "string"
                ? comp.text
                : comp.body && typeof comp.body === "object" && typeof comp.body.text === "string"
                  ? comp.body.text
                  : "";
        const re = /\{\{\s*(\d+)\s*\}\}/g;
        let m: RegExpExecArray | null;
        while ((m = re.exec(txt)) !== null) {
            const d = parseInt(m[1], 10);
            if (!Number.isNaN(d)) fromPlaceholders = Math.max(fromPlaceholders, d);
        }
        const ex = comp.example;
        if (ex && typeof ex === "object" && Array.isArray(ex.body_text) && ex.body_text.length > 0) {
            const first = ex.body_text[0];
            if (Array.isArray(first)) fromExample = Math.max(fromExample, first.length);
            else if (typeof first === "string") fromExample = Math.max(fromExample, 1);
        }
    }
    return Math.max(fromPlaceholders, fromExample);
}

export function isNoahOfficialTemplateSource(source: unknown): boolean {
    const s = String(source ?? "")
        .trim()
        .toLowerCase()
        .replace(/\s+/g, "_");
    if (s === "noah_oficial" || s === "noah") return true;
    if (s === "noah_official") return true;
    // Ex.: `noah_oficial_whatsapp` ou respostas legadas do WP
    if (s.startsWith("noah_oficial") || s.includes("_noah_oficial")) return true;
    return false;
}

/**
 * NOAH Oficial: variáveis em `{{1}}`, `{{2}}` no texto do template ou contagem via `components[].example.body_text`.
 * Aceita `source` em qualquer caixa (`noah_oficial`, `NOAH_OFICIAL`, …).
 */
export function listNoahOfficialVariableKeysFromTemplate(template: unknown): string[] {
    if (!template || typeof template !== "object") return [];
    const t = template as Record<string, any>;
    if (!isNoahOfficialTemplateSource(t.source)) return [];

    const text = collectPlaceholdersSourceText(template);
    const fromNumeric = extractNoahNumericPlaceholderKeys(text);
    if (fromNumeric.length > 0) return fromNumeric;

    const fromText = extractVariables(text);
    if (fromText.length > 0) return fromText;

    const raw = (t.raw_data && typeof t.raw_data === "object" ? t.raw_data : t) as Record<string, any>;
    const nComp = inferNoahParameterCountFromComponents(t.components);
    const nRaw = inferNoahParameterCountFromComponents(raw.components);
    const n = Math.max(nComp, nRaw);
    if (n <= 0) return [];
    return Array.from({ length: n }, (_, i) => String(i + 1));
}

export function buildInitialVariableMappingFromNoahOfficial(template: unknown): Record<string, VarMapping> | null {
    const keys = listNoahOfficialVariableKeysFromTemplate(template);
    if (keys.length === 0) return null;
    const init: Record<string, VarMapping> = {};
    for (const k of keys) {
        init[k] = { type: "field", value: "nome" };
    }
    return init;
}

/**
 * GOSAC Oficial: ordem das chaves = `variableComponents[].variable` (ex.: `{{Var1}}`), como o PHP/Nest usam no HSM.
 * Se a API não mandar `variableComponents`, cai no texto do template (`{{…}}` / `var1`).
 */
export function listGosacOfficialVariableKeysFromTemplate(template: unknown): string[] {
    if (!template || typeof template !== "object") return [];
    const t = template as Record<string, any>;
    if (t.source !== "gosac_oficial") return [];

    const vc = t.variableComponents;
    if (Array.isArray(vc) && vc.length > 0) {
        const keys: string[] = [];
        for (const row of vc) {
            if (!row || typeof row !== "object") continue;
            const v = String((row as { variable?: string }).variable ?? "").trim();
            if (v) keys.push(v);
        }
        if (keys.length > 0) return keys;
    }

    const text = collectPlaceholdersSourceText(template);
    return extractVariables(text);
}

export function buildInitialVariableMappingFromGosacOfficial(template: unknown): Record<string, VarMapping> | null {
    const keys = listGosacOfficialVariableKeysFromTemplate(template);
    if (keys.length === 0) return null;
    const init: Record<string, VarMapping> = {};
    for (const k of keys) {
        init[k] = { type: "field", value: "nome" };
    }
    return init;
}

/**
 * Extracts variable names from a template string with {{varN}}, {n}, -var-, etc.
 * Returns sorted unique list (números na ordem natural: 1, 2, 10).
 */
export function extractVariables(text: string): string[] {
    if (!text) return [];
    // {{name}}, {name}, [name], -var4- (Ótima HSM), var1
    const regex = /\{\{([\w.-]+)\}\}|\{([\w.-]+)\}|\[([\w.-]+)\]|(-[\w.-]+-)|\b(var\d+)\b/g;
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
