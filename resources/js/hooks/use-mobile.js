import * as React from "react"

// 1024 (Tailwind's lg) rather than 768: below lg the sidebar goes off-canvas as a
// sheet instead of reserving a permanent 16rem column. Keep this in step with the
// lg:block / lg:flex gates on the desktop branch in Components/ui/sidebar.jsx --
// if the CSS boundary and this one disagree, the desktop sidebar flashes on first
// paint before the effect below switches it to the drawer.
const MOBILE_BREAKPOINT = 1024

export function useIsMobile() {
  const [isMobile, setIsMobile] = React.useState(undefined)

  React.useEffect(() => {
    const mql = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`)
    const onChange = () => {
      setIsMobile(window.innerWidth < MOBILE_BREAKPOINT)
    }
    mql.addEventListener("change", onChange)
    setIsMobile(window.innerWidth < MOBILE_BREAKPOINT)
    return () => mql.removeEventListener("change", onChange);
  }, [])

  return !!isMobile
}
