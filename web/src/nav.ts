/** Hash-based navigation, shared by every page component. */
export function navigate(path: string): void {
  window.location.hash = path;
}
