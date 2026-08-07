import React from 'react'
import { Card } from '../ui/card'
import { TrendingUp, TrendingDown, Minus } from 'lucide-react'
import { cn } from "@/lib/utils"

function SummaryCard({
    value,
    title,
    icon: Icon,      // Ibinabalik natin si Icon para sa mga naunang page modules mo
    image,          // Bagong prop para sa image/logo paths (e.g., SSS, PhilHealth logos)
    description,
    trend, 
    trendValue, 
    variant = "default" 
}) {
    return (
        <Card className="group p-6 relative overflow-hidden transition-all duration-500 hover:shadow-2xl hover:-translate-y-1.5 border-slate-200 bg-white shadow-sm ring-1 ring-slate-950/5">
            
            {/* Animated Gradient Background on Hover */}
            <div className="absolute inset-0 bg-linear-to-br from-transparent via-transparent to-[#0344a4]/5 opacity-0 group-hover:opacity-100 transition-opacity duration-500" />

            {/* Side Accent Line */}
            <div className={cn(
                "absolute top-0 left-0 w-1.5 h-full transition-all duration-300",
                variant === "warning" ? "bg-amber-500" : "bg-[#0344a4]",
                "opacity-0 group-hover:opacity-100"
            )} />

            <div className="flex justify-between items-start relative z-10">
                <div className="space-y-3">
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-[0.15em]">
                        {title}
                    </p>
                    
                    <div className="flex flex-col">
                        <h3 className={cn(
                            "font-extrabold tracking-tight transition-colors",
                            value?.length > 10 ? "text-2xl" : "text-4xl", 
                            "text-slate-900"
                        )}>
                            {value || "0"}
                        </h3>
                        
                        {/* Improved Trend/Description Logic */}
                        {(trend || description) && (
                            <div className="flex items-center gap-2 mt-2">
                                {trend && (
                                    <div className={cn(
                                        "flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-bold",
                                        trend === 'up' ? "bg-emerald-50 text-emerald-600" : 
                                        trend === 'down' ? "bg-rose-50 text-rose-600" : "bg-slate-50 text-slate-600"
                                    )}>
                                        {trend === 'up' && <TrendingUp className="w-3 h-3" />}
                                        {trend === 'down' && <TrendingDown className="w-3 h-3" />}
                                        {trend === 'neutral' && <Minus className="w-3 h-3" />}
                                        {trendValue}
                                    </div>
                                )}
                                <span className="text-[11px] font-medium text-slate-500 leading-none">
                                    {description}
                                </span>
                            </div>
                        )}
                    </div>
                </div>

                {/* --- FLEXIBLE VISUAL RENDERING (IMAGE OR ICON) --- */}
                {/* Kung may 'image' prop, ito ang priority. Kung wala, babagsak kay 'Icon' component */}
                {image ? (
                    <div className="p-2.5 rounded-2xl bg-slate-50 border border-slate-100 w-12 h-12 flex items-center justify-center transition-all duration-500 transform group-hover:rotate-6">
                        <img 
                            src={image} 
                            alt={`${title} logo`} 
                            className="w-7 h-7 object-contain" 
                        />
                    </div>
                ) : Icon ? (
                    <div className={cn(
                        "p-3 rounded-2xl transition-all duration-500 transform group-hover:rotate-6 w-12 h-12 flex items-center justify-center",
                        variant === "warning" 
                            ? "bg-amber-50 text-amber-600 group-hover:bg-amber-500 group-hover:text-white" 
                            : "bg-blue-50 text-[#0344a4] group-hover:bg-[#0344a4] group-hover:text-white"
                    )}>
                        <Icon className="w-6 h-6" />
                    </div>
                ) : null}
            </div>

            {/* Subtle Decorative Circle */}
            <div className="absolute -right-8 -bottom-8 w-32 h-32 bg-slate-50 rounded-full group-hover:scale-150 transition-transform duration-700 z-0 opacity-50" />
        </Card>
    )
}

export default SummaryCard