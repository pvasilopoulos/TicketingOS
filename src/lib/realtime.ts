import "server-only";

// In-process SSE registry. Shared via globalThis so route handlers and server
// actions use the same instance regardless of bundling. Single-instance only
// (for multi-instance deployments, back this with Redis pub/sub).
type Client = { userId: string; send: (data: string) => void };

const g = globalThis as unknown as { __sseClients?: Set<Client> };
g.__sseClients ??= new Set<Client>();
const clients = g.__sseClients;

export function addClient(c: Client): () => void {
  clients.add(c);
  return () => {
    clients.delete(c);
  };
}

/** Push a JSON payload to every open stream of a user. */
export function publishToUser(userId: string, payload: unknown) {
  const frame = `data: ${JSON.stringify(payload)}\n\n`;
  for (const c of clients) {
    if (c.userId === userId) {
      try {
        c.send(frame);
      } catch {
        clients.delete(c);
      }
    }
  }
}
