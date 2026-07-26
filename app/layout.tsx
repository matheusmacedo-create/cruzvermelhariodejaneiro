import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = { title: "CVB Links", description: "Central de acessos da Cruz Vermelha Brasileira" };

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="pt-BR"><body>{children}</body></html>;
}
