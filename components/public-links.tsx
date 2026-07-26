"use client";
import { ArrowRight, BookOpen, ChevronRight, ExternalLink, Facebook, Globe2, GraduationCap, HandHeart, Heart, Instagram, MessageCircle, Share2, Users, Youtube } from "lucide-react";
import { Logo } from "./logo";

const links = [
  { label: "Conheça nossos cursos", desc: "Capacitação que salva vidas", icon: GraduationCap, tone: "red" },
  { label: "Faça uma doação", desc: "Sua ajuda transforma histórias", icon: Heart, tone: "dark" },
  { label: "Seja voluntário", desc: "Faça parte dessa missão", icon: Users, tone: "plain" },
  { label: "Fale pelo WhatsApp", desc: "Atendimento rápido e direto", icon: MessageCircle, tone: "plain" },
  { label: "Acesse o site oficial", desc: "Saiba mais sobre a CVB-RJ", icon: Globe2, tone: "plain" },
];

export default function PublicLinks() {
  async function share() {
    const data = { title: "Cruz Vermelha Brasileira — Rio de Janeiro", url: location.href };
    if (navigator.share) await navigator.share(data); else await navigator.clipboard.writeText(location.href);
  }
  return <main className="publicPage">
    <div className="topPattern" />
    <section className="publicShell">
      <button className="shareButton" onClick={share} aria-label="Compartilhar página"><Share2 size={19} /></button>
      <header className="hero">
        <Logo />
        <div className="official"><span>✓</span> Página oficial</div>
        <h1>Cruz Vermelha Brasileira</h1>
        <p className="place">Rio de Janeiro</p>
        <p className="mission">Ajuda humanitária, educação, saúde e transformação social.</p>
      </header>
      <div className="quickStats"><div><b>+115</b><span>anos no Brasil</span></div><i /><div><b>24h</b><span>prontos para ajudar</span></div><i /><div><b>7</b><span>princípios</span></div></div>
      <section className="linkStack" aria-label="Links principais">
        {links.map(({ label, desc, icon: Icon, tone }) => <a href={`/r/demo?destino=${encodeURIComponent(label)}`} className={`linkCard ${tone}`} key={label}>
          <span className="linkIcon"><Icon size={23} /></span><span className="linkCopy"><b>{label}</b><small>{desc}</small></span><ChevronRight size={22} />
        </a>)}
      </section>
      <section className="campaign">
        <div className="campaignArt"><HandHeart size={48} /><span>PROJETO<br /><b>CORES</b></span></div>
        <div><span className="eyebrow">Campanha em destaque</span><h2>Projeto Cores</h2><p>Levando cuidado, dignidade e esperança para quem mais precisa.</p><a href="/r/campanha">Conheça o projeto <ArrowRight size={16} /></a></div>
      </section>
      <section className="courses"><div className="sectionTitle"><div><span className="eyebrow">Aprenda com a gente</span><h2>Cursos e eventos</h2></div><BookOpen /></div>
        <div className="courseGrid"><a href="/r/primeiros-socorros"><span className="courseVisual aid"><Heart /></span><b>Primeiros Socorros</b><small>Formação essencial</small><span>Saiba mais <ExternalLink size={13}/></span></a><a href="/r/voluntariado"><span className="courseVisual volunteer"><Users /></span><b>Formação de Voluntários</b><small>Prepare-se para ajudar</small><span>Saiba mais <ExternalLink size={13}/></span></a></div>
      </section>
      <section className="social"><span>Acompanhe nosso trabalho</span><div><a aria-label="Instagram" href="#"><Instagram /></a><a aria-label="Facebook" href="#"><Facebook /></a><a aria-label="Youtube" href="#"><Youtube /></a></div></section>
      <footer><Logo compact /><p>Cruz Vermelha Brasileira — Filial Rio de Janeiro<br />Rua Prefeito Olímpio de Melo, 1976 — Benfica</p><nav><a href="#">Política de Privacidade</a><a href="#">Termos de Uso</a></nav><small>© 2026 Cruz Vermelha Brasileira. Todos os direitos reservados.</small></footer>
    </section>
  </main>;
}
