import { useEffect, useState } from "react";
import { router } from "@inertiajs/react";
import { Search } from "lucide-react";

import { Input } from "@/Components/ui/input";

/**
 * Debounced server-side search for a Record page.
 *
 * Each Record page owns one table, so the request goes back to that page's own
 * url -- the old combined screen hard-coded /records for all three.
 */
export default function RecordSearchInput({ url, placeholder, initialValue = "" }) {
    const [searchTerm, setSearchTerm] = useState(initialValue);

    // Keep in step with the server when a link (pagination, sidebar) changes it.
    useEffect(() => {
        setSearchTerm(initialValue);
    }, [initialValue]);

    useEffect(() => {
        const timer = setTimeout(() => {
            if (searchTerm !== initialValue) {
                router.get(
                    url,
                    { search: searchTerm },
                    { preserveState: true, replace: true }
                );
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [searchTerm]);

    return (
        <div className="relative w-full md:w-72">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <Input
                type="text"
                placeholder={placeholder}
                value={searchTerm}
                onChange={(event) => setSearchTerm(event.target.value)}
                className="bg-white pl-9"
            />
        </div>
    );
}
