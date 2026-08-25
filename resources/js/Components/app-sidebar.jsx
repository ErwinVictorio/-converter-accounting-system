import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from "@/components/ui/sidebar";
import { Link, usePage } from "@inertiajs/react";
import {
    LayoutDashboard,
    Building2,
    Building,
    Briefcase,
    FileDown,
    FileUp,
    Ship,
    Truck,
    Users,
    LogOut
} from "lucide-react";


/*
 * Grouped by what the screen is for, not by how often it is used:
 *
 *  - Main Menu           the overview.
 *  - Data & Transactions the monthly work -- bring figures in, then take a DAT out.
 *  - Master Data         the reference records those screens read from, set up once
 *                        and revisited only when a customer, supplier, broker or
 *                        withholding company changes.
 *
 * Every url here is an existing route; the labels are display text only.
 */
const mainItems = [
    {
        title: "Dashboard",
        url: "/",
        icon: LayoutDashboard,
    },
];

const transactionItems = [
    {
        title: "Import Data",
        url: "/records",
        icon: FileUp,
    },
    {
        title: "Importation",
        url: "/importation",
        icon: Ship,
    },
    {
        title: "Generate DAT File",
        url: "/generate-datfile",
        icon: FileDown,
    },
];

const masterDataItems = [
    {
        title: "Customers",
        url: "/customers",
        icon: Users,
    },
    {
        title: "Suppliers",
        url: "/suppliers",
        icon: Truck,
    },
    {
        title: "Brokers",
        url: "/brokers",
        icon: Briefcase,
    },
    {
        title: "Companies",
        url: "/withholding-companies",
        icon: Building,
    },
];

const menuGroups = [
    { label: "Main Menu", items: mainItems },
    { label: "Data & Transactions", items: transactionItems },
    { label: "Master Data", items: masterDataItems },
];

export function AppSidebar() {
    const { url: currentUrl, props } = usePage();
    const user = props?.auth?.user;
    const { isMobile, setOpenMobile } = useSidebar();

    // Kusa nitong isasara ang mobile drawer pagkatapos mag-click ng link
    const handleNavClick = () => {
        if (isMobile) {
            setOpenMobile(false);
        }
    };

    // Alisin ang query string, tapos tugma sa exact o parent na route
    const currentPath = currentUrl.split("?")[0];
    const isActiveUrl = (url) =>
        url === "/"
            ? currentPath === "/"
            : currentPath === url || currentPath.startsWith(`${url}/`);

    return (
        // collapsible="icon" = nagko-collapse sa 3rem rail sa desktop, sheet drawer sa mobile
        <Sidebar collapsible="icon">
            {/* Header — bumabagay sa expanded rail at sa makitid na icon rail */}
            <SidebarHeader className="h-16 flex items-center border-b border-gray-200 bg-white px-4 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0">
                <div className="flex items-center gap-3 font-bold text-gray-800 text-base overflow-hidden">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white">
                        <Building2 className="h-5 w-5" />
                    </div>
                    <span className="truncate group-data-[collapsible=icon]:hidden">
                        Fortress Steel
                    </span>
                </div>
            </SidebarHeader>

            {/* Content Links */}
            <SidebarContent className="bg-white px-2 py-3">
                {menuGroups.map((group) => (
                    // py-1 over the shipped p-2: three group labels instead of one, so
                    // the tighter vertical padding keeps the whole list on screen at
                    // 768px height without touching the horizontal rhythm.
                    <SidebarGroup key={group.label} className="py-1">
                        {/* mb-0 in the icon rail -- the label collapses to opacity-0
                            with a negative top margin, so its bottom margin would
                            otherwise leave a gap per group. */}
                        <SidebarGroupLabel className="mb-1 px-2 text-xs font-semibold uppercase tracking-wider text-gray-400 group-data-[collapsible=icon]:mb-0">
                            {group.label}
                        </SidebarGroupLabel>
                        <SidebarGroupContent>
                            <SidebarMenu className="space-y-1">
                                {group.items.map((item) => {
                                    const IconComponent = item.icon;
                                    const isActive = isActiveUrl(item.url);

                                    return (
                                        <SidebarMenuItem key={item.title}>
                                            <SidebarMenuButton
                                                asChild
                                                isActive={isActive}
                                                tooltip={item.title}
                                                className="text-sm font-medium transition-colors hover:bg-gray-100 data-[active=true]:bg-blue-50 data-[active=true]:text-blue-600"
                                            >
                                                <Link
                                                    href={item.url}
                                                    onClick={handleNavClick}
                                                    className="flex items-center gap-3 w-full"
                                                >
                                                    <IconComponent className="h-4 w-4 shrink-0" />
                                                    <span className="truncate group-data-[collapsible=icon]:hidden">
                                                        {item.title}
                                                    </span>
                                                </Link>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                    );
                                })}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                ))}
            </SidebarContent>

            {/* Footer */}
            <SidebarFooter className="border-t border-gray-100 bg-white p-2">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            tooltip="Log out"
                            className="text-sm font-medium text-gray-600 transition-colors hover:bg-red-50 hover:text-red-600"
                        >
                            {/* method="post" so Inertia sends the CSRF token with it */}
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                type="button"
                                onClick={handleNavClick}
                                className="flex w-full items-center gap-3"
                            >
                                <LogOut className="h-4 w-4 shrink-0" />
                                <span className="truncate group-data-[collapsible=icon]:hidden">
                                    Log out
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                <div className="px-2 pb-1 text-xs text-gray-400 group-data-[collapsible=icon]:hidden">
                    {user?.name && (
                        <p className="truncate font-medium text-gray-500">
                            {user.name}
                        </p>
                    )}
                    <span>System v1.0</span>
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}
