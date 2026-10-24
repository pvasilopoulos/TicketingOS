import { ResetForm } from "@/components/app/ResetForm";

export default async function ResetPage({
  searchParams,
}: {
  searchParams: Promise<{ token?: string }>;
}) {
  const { token } = await searchParams;
  return <ResetForm token={token ?? ""} />;
}
