import { NextResponse } from "next/server";
import { getCurrentUser, getEffectiveTenantId } from "@/lib/auth";
import { saveInlineImage } from "@/lib/storage";

export async function POST(req: Request) {
  const user = await getCurrentUser();
  if (!user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }
  const tenantId = await getEffectiveTenantId(user);
  if (!tenantId) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const form = await req.formData();
  const file = form.get("file");
  if (!(file instanceof File)) {
    return NextResponse.json({ error: "No file" }, { status: 400 });
  }

  const url = await saveInlineImage(file, {
    tenantId,
    uploaderId: user.id,
  });
  if (!url) {
    return NextResponse.json(
      { error: "Invalid image (PNG/JPEG/GIF/WebP up to 5MB)" },
      { status: 400 },
    );
  }

  return NextResponse.json({ url });
}
