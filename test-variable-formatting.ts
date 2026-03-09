
const variables_map = {
    "var1": { "type": "field", "value": "nome" },
    "var2": { "type": "text", "value": "Valor Fixo" }
};

const item = {
    "nome": "Anderson Teste"
};

const resolvedVariables = {};
if (variables_map) {
    for (const [varName, mapping] of Object.entries(variables_map)) {
        const hyphenatedVarName = `-${varName}-`;
        if (mapping.type === 'field') {
            resolvedVariables[hyphenatedVarName] = item[mapping.value] ?? '';
        } else {
            resolvedVariables[hyphenatedVarName] = mapping.value ?? '';
        }
    }
}

console.log("Resolved Variables:", JSON.stringify(resolvedVariables, null, 2));

const expected = {
    "-var1-": "Anderson Teste",
    "-var2-": "Valor Fixo"
};

if (JSON.stringify(resolvedVariables) === JSON.stringify(expected)) {
    console.log("✅ Test Passed!");
} else {
    console.log("❌ Test Failed!");
    process.exit(1);
}
