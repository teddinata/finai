const fs = require("fs")
const { parse } = require("/Users/mymac/projects/benah-financial-ai/frontend/node_modules/@vue/compiler-dom/dist/compiler-dom.cjs.js")

const content = fs.readFileSync("/Users/mymac/projects/benah-financial-ai/frontend/src/views/SavingsView.vue", "utf-8")
try {
  parse(content, { onError: err => console.error("Vue Error:", err.message, "at line", err.loc?.start?.line) })
  console.log("Vue Template parsing successful!")
} catch (e) {
  console.error("Fatal Parse Error:", e)
}
