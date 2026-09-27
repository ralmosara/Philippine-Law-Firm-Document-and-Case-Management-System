/**
 * Renders search snippets where the server marked hits as ⟦like this⟧.
 * Built from text nodes only, so file content can never inject markup.
 */
export function Highlight({ text, className }: { text: string; className?: string }) {
  const parts = text.split(/[⟦⟧]/)
  return (
    <span className={className}>
      {parts.map((part, i) =>
        i % 2 === 1 ? (
          <mark key={i} className="rounded-sm bg-warning-container px-0.5 text-on-warning-container">{part}</mark>
        ) : (
          <span key={i}>{part}</span>
        ),
      )}
    </span>
  )
}
