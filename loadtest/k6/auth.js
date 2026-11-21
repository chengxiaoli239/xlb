export function csrfTokenFrom(payload) {
  const token = payload?.data?.token;
  return typeof token === 'string' && token.trim() !== '' ? token : null;
}

export function requiredTestPassword(value) {
  if (typeof value !== 'string' || value === '') {
    throw new Error('Set TEST_PASSWORD for an isolated local test account before running authenticated scenarios.');
  }
  return value;
}
