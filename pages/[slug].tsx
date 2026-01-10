import { useRouter } from 'next/router';

export default function PortalPage() {
  const router = useRouter();
  const { slug } = router.query;

  if (!slug) {
    return (
      <main>
        <p>Ładowanie portalu...</p>
      </main>
    );
  }

  return (
    <main>
      <h1>Portal: {slug}</h1>
      <p>To jest przykładowy portal o nazwie: {slug}.</p>
    </main>
  );
}
