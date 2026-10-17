import { cva } from "class-variance-authority";

export { default as Badge } from "./Badge.vue";

export const badgeVariants = cva(
  "rce-inline-flex rce-items-center rce-rounded-full rce-border rce-px-3 rce-py-1.5 rce-text-xs rce-font-medium rce-transition-colors focus:rce-outline-none focus:rce-ring-2 focus:rce-ring-ring focus:rce-ring-offset-2",
  {
    variants: {
      variant: {
        default:
          "rce-border-transparent rce-bg-primary rce-text-primary-foreground hover:rce-bg-primary/80",
        secondary:
          "rce-border-gray-300/50 rce-bg-gray-50/50 rce-text-gray-700",
        success:
          "rce-border-emerald-300/50 rce-bg-emerald-50/50 rce-text-emerald-700",
        warning:
          "rce-border-amber-300/50 rce-bg-amber-50/50 rce-text-amber-700",
        info:
          "rce-border-sky-300/50 rce-bg-sky-50/50 rce-text-sky-700",
        danger:
          "rce-border-rose-300/50 rce-bg-rose-50/50 rce-text-rose-700",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
);
