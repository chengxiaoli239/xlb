import { act } from 'react';

export async function fireEvent(element: HTMLElement, type: string, options: { value?: string } = {}) {
  await act(async () => {
    if (options.value !== undefined
      && (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement)) {
      const prototype = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      const setter = Object.getOwnPropertyDescriptor(prototype, 'value')?.set;
      setter?.call(element, options.value);
    }
    element.dispatchEvent(new Event(type, { bubbles: true, cancelable: true }));
  });
}
