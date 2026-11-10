import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Conditional class names, later Tailwind classes winning over earlier ones (`clsx` + `tailwind-merge`). */
export const cx = (...inputs: ClassValue[]): string => twMerge(clsx(inputs));
