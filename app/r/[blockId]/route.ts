import { NextRequest, NextResponse } from "next/server";
export function GET(request: NextRequest) {
  const destination = request.nextUrl.searchParams.get("destino");
  const fallback = new URL("/rio", request.url);
  if (!destination) return NextResponse.redirect(fallback, 302);
  const urls: Record<string, string> = { "Fale pelo WhatsApp": "https://wa.me/552125025200", "Acesse o site oficial": "https://www.cruzvermelha.org.br" };
  return NextResponse.redirect(urls[destination] ?? fallback, 302);
}
