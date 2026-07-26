export type Role = "super_admin" | "admin" | "publisher" | "editor" | "analyst" | "viewer";
export type Action = "organization:update" | "page:create" | "user:manage" | "block:update" | "review:submit" | "page:publish" | "analytics:view" | "audit:view" | "content:view";

const grants: Record<Action, readonly Role[]> = {
  "organization:update": ["super_admin"], "page:create": ["super_admin", "admin"], "user:manage": ["super_admin", "admin"],
  "block:update": ["super_admin", "admin", "publisher", "editor"], "review:submit": ["super_admin", "admin", "publisher", "editor"],
  "page:publish": ["super_admin", "admin", "publisher"], "analytics:view": ["super_admin", "admin", "publisher", "editor", "analyst"],
  "audit:view": ["super_admin", "admin", "analyst"], "content:view": ["super_admin", "admin", "publisher", "editor", "analyst", "viewer"],
};
export function can(role: Role, action: Action): boolean { return grants[action].includes(role); }
