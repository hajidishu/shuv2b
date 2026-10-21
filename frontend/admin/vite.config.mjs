import { defineConfig } from "vite";
export default defineConfig({
    base: "./",
    build: {
        manifest: "manifest.json",
        target: "es2022",
        rollupOptions: {
            output: {
                manualChunks: { antd: ["antd"] },
            },
            onwarn(warning, warn) {
                if (warning.code === 'MODULE_LEVEL_DIRECTIVE' && warning.message.includes('use client')) return;
                warn(warning);
            },
        },
    },
    server: { host: "127.0.0.1" },
});
