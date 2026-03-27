import React from "react";
import { Smartphone } from "lucide-react";
import { collectPlaceholdersSourceText } from "./TemplateVariableMapper";

interface Props {
    template: any;                              // Raw template object from Ótima API
    resolvedVariables?: Record<string, string>; // { var1: "João", var2: "Empresa X" }
    channel?: "rcs" | "wpp";
}

function applyVars(text: string, vars: Record<string, string>): string {
    if (!text) return "";
    let out = text;
    const keys = Object.keys(vars).sort((a, b) => b.length - a.length);
    for (const k of keys) {
        if (vars[k] === undefined) continue;
        const esc = k.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
        out = out.replace(new RegExp(esc, "g"), vars[k]);
    }
    return out.replace(/\{\{([\w.-]+)\}\}|\{([\w.-]+)\}|\[([\w.-]+)\]|-([\w.-]+)-|\b(var\d+)\b/g, (match, g1, g2, g3, g4, g5) => {
        const name = g1 || g2 || g3 || g4 || g5;
        return vars[name] ?? match;
    });
}

export function RcsMessagePreview({ template, resolvedVariables = {}, channel = "rcs" }: Props) {
    if (!template) {
        return (
            <div className="flex flex-col items-center justify-center h-full min-h-[300px] rounded-xl border border-dashed border-border text-muted-foreground text-sm gap-2 p-6">
                <Smartphone className="h-10 w-10 opacity-30" />
                <span className="text-center">Selecione um template para visualizar a mensagem</span>
            </div>
        );
    }

    // Extract content from raw_data or from template fields directly
    const raw = template.raw_data ?? template;

    // --- RCS Rich Card ---
    const richCard = raw.rich_card ?? raw.richCard ?? null;
    const imageUrl: string | null = richCard?.image_url ?? raw.image_url ?? null;
    const wppBodyText =
        channel === "wpp" ? collectPlaceholdersSourceText(template) : "";
    const title: string = applyVars(richCard?.title ?? raw.title ?? "", resolvedVariables);
    const description: string = applyVars(
        richCard?.description ??
            richCard?.text ??
            raw.description ??
            raw.text ??
            raw.content ??
            template.content ??
            wppBodyText ??
            "",
        resolvedVariables
    );
    const buttons: any[] = richCard?.suggestions ?? raw.suggestions ?? [];
    const templateName: string = template.name ?? "Template";

    const isRcs = channel === "rcs";

    return (
        <div className="flex flex-col items-center gap-3">
            <span className="text-xs text-muted-foreground font-medium uppercase tracking-wide">
                Pré-visualização — {isRcs ? "RCS" : "WhatsApp"}
            </span>

            {/* Phone frame */}
            <div
                className="relative bg-black rounded-[44px] shadow-2xl"
                style={{ width: 280, padding: "14px 10px", boxShadow: "0 0 0 2px #333, 0 20px 60px rgba(0,0,0,.5)" }}
            >
                {/* Speaker notch */}
                <div className="absolute top-4 left-1/2 -translate-x-1/2 w-16 h-5 bg-black rounded-full z-10 border-2 border-[#222]" />

                {/* Screen */}
                <div
                    className="bg-[#F2F2F7] rounded-[36px] overflow-hidden"
                    style={{ minHeight: 500, paddingTop: 36, paddingBottom: 16 }}
                >
                    {/* Status bar */}
                    <div className="flex items-center justify-between px-5 pb-2 text-[10px] text-black/70 font-medium">
                        <span>9:41</span>
                        <span className="flex gap-1 items-center">
                            <svg width="14" height="10" viewBox="0 0 14 10" fill="none"><rect x="0" y="3" width="2" height="7" rx="1" fill="currentColor" fillOpacity=".4" /><rect x="3" y="2" width="2" height="8" rx="1" fill="currentColor" fillOpacity=".6" /><rect x="6" y="1" width="2" height="9" rx="1" fill="currentColor" fillOpacity=".8" /><rect x="9" y="0" width="2" height="10" rx="1" fill="currentColor" /></svg>
                            <svg width="16" height="12" viewBox="0 0 24 12" fill="none"><path d="M1 4C4.31 1.33 8.5 0 12 0s7.69 1.33 11 4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" fill="none" /><path d="M4.5 7.5C6.67 5.83 9.25 5 12 5s5.33.83 7.5 2.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" fill="none" /><circle cx="12" cy="11" r="1.5" fill="currentColor" /></svg>
                            🔋
                        </span>
                    </div>

                    {/* Chat header */}
                    <div className="bg-white flex items-center gap-2 px-4 py-2 border-b border-gray-200 shadow-sm">
                        <div className="h-8 w-8 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                            {isRcs ? "R" : "W"}
                        </div>
                        <div>
                            <p className="text-[12px] font-semibold text-gray-900 leading-tight">{templateName}</p>
                            <p className="text-[10px] text-gray-500">{isRcs ? "Mensagem RCS" : "WhatsApp"}</p>
                        </div>
                    </div>

                    {/* Messages area */}
                    <div className="px-3 py-3 space-y-2 overflow-y-auto" style={{ maxHeight: 380 }}>
                        {/* Incoming bubble */}
                        <div className="flex flex-col gap-1 items-start max-w-[90%]">
                            {/* RCS Card */}
                            <div className="bg-white rounded-2xl rounded-tl-md shadow-sm overflow-hidden border border-gray-100 w-full">
                                {imageUrl && (
                                    <div className="w-full bg-gray-200 overflow-hidden" style={{ height: 120 }}>
                                        <img
                                            src={imageUrl}
                                            alt="template"
                                            className="w-full h-full object-cover"
                                            onError={(e) => {
                                                (e.target as HTMLImageElement).style.display = "none";
                                            }}
                                        />
                                    </div>
                                )}
                                {!imageUrl && (
                                    <div
                                        className="w-full flex items-center justify-center bg-gradient-to-br from-blue-100 to-purple-100"
                                        style={{ height: 80 }}
                                    >
                                        <Smartphone className="h-8 w-8 text-blue-300" />
                                    </div>
                                )}

                                <div className="px-3 py-2 space-y-1">
                                    {title && (
                                        <p className="text-[12px] font-semibold text-gray-900 leading-snug whitespace-pre-wrap">{title}</p>
                                    )}
                                    {description && (
                                        <p className="text-[11px] text-gray-700 leading-snug whitespace-pre-wrap">{description}</p>
                                    )}
                                    <p className="text-[9px] text-gray-400 text-right">10:23</p>
                                </div>

                                {/* Buttons */}
                                {buttons.length > 0 && (
                                    <div className="border-t border-gray-100 divide-y divide-gray-100">
                                        {buttons.slice(0, 3).map((btn: any, i: number) => (
                                            <div
                                                key={i}
                                                className="py-2 text-center text-[11px] font-medium text-blue-600 cursor-pointer hover:bg-blue-50 transition-colors"
                                            >
                                                {btn.text ?? btn.label ?? btn.title ?? "Botão"}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Input bar */}
                    <div className="mx-3 mt-1 bg-white rounded-full border border-gray-200 flex items-center px-3 py-1.5 gap-2">
                        <span className="text-[10px] text-gray-400 flex-1">Responder...</span>
                        <div className="h-5 w-5 rounded-full bg-blue-500 flex items-center justify-center">
                            <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M1 5H9M6 2l3 3-3 3" stroke="white" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" /></svg>
                        </div>
                    </div>
                </div>

                {/* Home bar */}
                <div className="flex justify-center mt-2">
                    <div className="w-24 h-1 bg-white/60 rounded-full" />
                </div>
            </div>

            <p className="text-[10px] text-muted-foreground text-center max-w-[240px]">
                Esta é uma pré-visualização aproximada. A aparência final pode variar por dispositivo.
            </p>
        </div>
    );
}
