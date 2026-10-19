import { headers } from "next/headers";
import { brandFromHost } from "@/lib/brand";
import { LLMS_EN } from "@/lib/llms";

/** appmitki.com for AI assistants (lib/llms.ts); not on the Sofabuilt host. */
export async function GET() {
  if (brandFromHost((await headers()).get("host")) === "sofabuilt") return new Response("Not found", { status: 404 });
  return new Response(LLMS_EN, { headers: { "content-type": "text/plain; charset=utf-8", "cache-control": "public, max-age=3600" } });
}
