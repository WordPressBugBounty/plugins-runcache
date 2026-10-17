import { cva } from "class-variance-authority";

export { default as Button } from "./Button.vue";

export const buttonVariants = cva(
  "rce-inline-flex rce-items-center rce-justify-center rce-gap-2 rce-whitespace-nowrap rce-rounded-full rce-text-sm rce-font-medium rce-transition-colors focus-visible:rce-outline-none focus-visible:rce-ring-1 focus-visible:rce-ring-ring disabled:rce-pointer-events-none disabled:rce-opacity-50 [&_svg]:rce-pointer-events-none [&_svg]:rce-size-4 [&_svg]:rce-shrink-0",
  {
    variants: {
      variant: {
        default:
          "rce-bg-gray-950 rce-text-primary-foreground hover:rce-bg-gray-950/90",
        primary:
          "rce-bg-primary-700 rce-text-primary-foreground hover:rce-bg-primary-600",
        destructive:
          "rce-bg-destructive rce-text-destructive-foreground hover:rce-bg-destructive/90",
        outline:
          "rce-border rce-border-input rce-bg-background hover:rce-bg-accent hover:rce-text-accent-foreground",
        secondary:
          "rce-bg-secondary rce-text-secondary-foreground hover:rce-bg-secondary/80",
        white: "rce-bg-white rce-text-gray-900 hover:rce-bg-white/80",
        ghost: "hover:rce-bg-accent hover:rce-text-accent-foreground",
        link: "rce-text-primary rce-underline-offset-4 hover:rce-underline",
      },
      size: {
        default: "rce-h-10 rce-px-4 rce-py-2",
        xs: "rce-h-8 rce-rounded-full rce-px-3",
        sm: "rce-h-9 rce-rounded-full rce-px-4",
        lg: "rce-h-10 rce-rounded-full rce-px-8",
        icon: "rce-h-10 rce-w-9",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
);
