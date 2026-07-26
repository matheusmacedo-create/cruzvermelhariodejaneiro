export function Logo({ compact = false }: { compact?: boolean }) {
  return <div className={`logo ${compact ? "logoCompact" : ""}`} aria-label="Cruz Vermelha Brasileira Rio de Janeiro">
    <span className="cross" aria-hidden="true" />
    <span><b>CRUZ VERMELHA<br />BRASILEIRA</b>{!compact && <small>RIO DE JANEIRO</small>}</span>
  </div>;
}
