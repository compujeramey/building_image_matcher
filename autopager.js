document.addEventListener('DOMContentLoaded', () => {
  if (typeof BuildingImporter === 'undefined' || window.stopAutoPagination) return;

  const next = BuildingImporter.nextPageUrl;
  setTimeout(() => {
    if (!window.stopAutoPagination) {
      window.location.href = next;
    }
  }, 1500); // Wait 1.5s before paging
});
