const accountOrigin = "http://127.0.0.1:5174";

document.querySelectorAll("[data-tab]").forEach((button) => {
  button.addEventListener("click", () => {
    const selected = button.dataset.tab;
    document.querySelectorAll("[data-tab]").forEach((tab) => {
      tab.setAttribute("aria-selected", String(tab === button));
    });
    document.querySelectorAll(".popup-panel").forEach((panel) => {
      panel.hidden = panel.id !== selected;
    });
  });
});

document.querySelectorAll("[data-page]").forEach((button) => {
  button.addEventListener("click", () => {
    const page = button.dataset.page;
    const url = new URL("/account", accountOrigin);
    url.searchParams.set("view", page);
    url.searchParams.set("source", "extension");
    chrome.tabs.create({ url: url.toString() });
  });
});
