import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * Vendored from shadcn, then rewritten for Tailwind 3.4 -- the upstream file was
 * v4 source, so `bg-card`, `shadow-xs`, `py-(--card-spacing)` and friends compiled
 * to nothing and every card rendered transparent and unpadded.
 *
 * Two conventions this file now relies on:
 *
 * 1. No default padding. Every call site in the app already passes its own
 *    (`p-5`, `p-4 sm:p-6`, `p-6`, `p-0`, `py-4`), so a base value would only
 *    double up or fight tailwind-merge.
 * 2. No default ring or border. Call sites pass `ring-1 ring-slate-950/5` or
 *    `border border-slate-100`, whichever the page uses.
 */
function Card({
  className,
  size = "default",
  ...props
}) {
  return (
    <div
      data-slot="card"
      data-size={size}
      className={cn(
        // No `flex flex-col` here: several call sites lay their card out as a row
        // (icon beside text) and a base flex-direction would override them,
        // because tailwind-merge treats `flex` and `flex-col` as separate groups.
        "group/card overflow-hidden rounded-xl bg-white text-sm shadow-sm",
        className
      )}
      {...props} />
  );
}

function CardHeader({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-header"
      className={cn(
        "group/card-header grid auto-rows-min items-start gap-1 rounded-t-xl has-[[data-slot=card-action]]:grid-cols-[1fr_auto] has-[[data-slot=card-description]]:grid-rows-[auto_auto]",
        className
      )}
      {...props} />
  );
}

function CardTitle({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-title"
      className={cn(
        "text-base font-medium leading-normal group-data-[size=sm]/card:text-sm",
        className
      )}
      {...props} />
  );
}

function CardDescription({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-description"
      className={cn("text-sm text-slate-500", className)}
      {...props} />
  );
}

function CardAction({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-action"
      className={cn(
        "col-start-2 row-span-2 row-start-1 self-start justify-self-end",
        className
      )}
      {...props} />
  );
}

function CardContent({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-content"
      className={cn("flex flex-col gap-3", className)}
      {...props} />
  );
}

function CardFooter({
  className,
  ...props
}) {
  return (
    <div
      data-slot="card-footer"
      className={cn("flex items-center rounded-b-xl", className)}
      {...props} />
  );
}

export {
  Card,
  CardHeader,
  CardFooter,
  CardTitle,
  CardAction,
  CardDescription,
  CardContent,
}
