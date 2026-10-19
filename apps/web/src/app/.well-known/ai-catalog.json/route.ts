import { headers } from "next/headers";
import { brandFromHost } from "@/lib/brand";
import { AI_CATALOG } from "@/lib/llms";

/** The Agentic Resource Discovery manifest that points AI agents to llms.txt (lib/llms.ts). */
export async function GET() {
  if (brandFromHost((await headers()).get("host")) === "sofabuilt") return new Response("Not found", { status: 404 });
  return Response.json(AI_CATALOG, { headers: { "cache-control": "public, max-age=3600" } });
}
