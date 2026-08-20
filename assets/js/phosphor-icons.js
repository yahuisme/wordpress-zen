(() => {
  const head = document.head;
  const link = document.createElement("link");

  link.rel = "stylesheet";
  link.type = "text/css";
  link.href = "https://unpkg.com/@phosphor-icons/web@2.0.3/src/regular/style.css";

  head.appendChild(link);
})();
