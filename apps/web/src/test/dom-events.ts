import { act } from 'react';

export async function fireEvent(element: HTMLElement, type: string, options: { value?: string } = {}) {
  await act(async () => {
    if (options.value !== undefined && element instanceof HTMLInputElement) {
      const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
      setter?.call(element, options.value);
    }
    element.dispatchEvent(new Event(type, { bubbles: true, cancelable: true }));
  });
}
