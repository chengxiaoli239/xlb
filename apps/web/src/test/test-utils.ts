import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { ReactNode } from 'react';

let root: Root | undefined;

export async function render(element: ReactNode) {
  const container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
  await act(async () => {
    root?.render(element);
  });
  return container;
}

export async function settle() {
  await act(async () => {
    await Promise.resolve();
    await Promise.resolve();
  });
}

export function cleanup() {
  if (!root) return;
  act(() => root?.unmount());
  root = undefined;
  document.body.replaceChildren();
}
