import { cva } from "class-variance-authority";

export { default as Alert } from "./Alert.vue";
export { default as AlertDescription } from "./AlertDescription.vue";
export { default as AlertTitle } from "./AlertTitle.vue";

export const alertVariants = cva(
  'rce-relative rce-w-full rce-rounded-xl rce-border rce-px-6 rce-py-4 rce-text-sm [&>svg+div]:rce-translate-y-[-3px] [&>svg]:rce-absolute [&>svg]:rce-left-5 [&>svg]:rce-top-4 md:[&>svg~*]:rce-pl-6',
  {
    variants: {
      variant: {
        default: 'rce-bg-background rce-text-foreground',
        warning:
          'rce-border-amber-300/50 rce-bg-amber-50/50 dark:rce-border-amber-500 [&>svg]:rce-text-amber-500',
        success:
          'rce-border-emerald-300/50 rce-bg-emerald-50/50 dark:rce-border-emerald-500 [&>svg]:rce-text-emerald-500',
        info:
          'rce-border-sky-300/50 rce-bg-sky-50/50 dark:rce-border-sky-500 [&>svg]:rce-text-sky-500',
        destructive:
          'rce-border-rose-300/50 rce-text-rose-500 dark:rce-border-rose-500 [&>svg]:rce-text-rose-500',
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
);
