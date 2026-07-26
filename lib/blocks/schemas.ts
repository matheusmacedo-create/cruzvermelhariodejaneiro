import { z } from "zod";

const linkFields = { url: z.string().url(), newTab: z.boolean().default(true), appendUtm: z.boolean().default(true) };
export const blockSchemas = {
  heading: z.object({ text: z.string().min(1).max(80) }),
  text: z.object({ text: z.string().min(1).max(600) }),
  button: z.object({ label: z.string().min(1).max(60), description: z.string().max(90).optional(), ...linkFields }),
  card: z.object({ imagePath: z.string(), title: z.string().max(80), subtitle: z.string().max(120).optional(), ctaLabel: z.string().max(30), ...linkFields }),
  campaign: z.object({ imagePath: z.string(), title: z.string().max(80), description: z.string().max(200), ctaLabel: z.string().max(30), urgent: z.boolean(), ...linkFields }),
  whatsapp: z.object({ items: z.array(z.object({ id: z.string().uuid(), label: z.string().max(40), phoneE164: z.string().regex(/^\+[1-9]\d{7,14}$/), prefilledMessage: z.string().max(300) })).min(1).max(6) }),
  socials: z.object({ items: z.array(z.object({ id: z.string().uuid(), network: z.enum(["instagram", "facebook", "youtube", "x", "linkedin", "tiktok", "site"]), url: z.string().url() })).min(1).max(8) }),
  divider: z.object({}),
  notice: z.object({ text: z.string().max(200), tone: z.enum(["info", "warning", "success"]) }),
} as const;

export type BlockType = keyof typeof blockSchemas;
