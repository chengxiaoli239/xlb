import { afterEach, vi } from 'vitest';
import { cleanup } from './test-utils';

afterEach(() => {
  cleanup();
  localStorage.clear();
  history.replaceState(null, '', '/client-preview.html');
  vi.unstubAllGlobals();
});
