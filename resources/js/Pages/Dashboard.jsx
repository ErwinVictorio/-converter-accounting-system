import React from "react";
import MainLayout from "@/Layouts/MainLayout";
import SummaryCard from "@/Components/Cards/SummaryCard";
import { FileChartColumn, User2 } from "lucide-react";

export default function Dashboard() {
  return (
    <>

      <div>
        <h1 className="text-2xl font-bold mb-4">Dashboard</h1>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
          <SummaryCard
            value={0}
            title="TOTAL RECORDS"
            icon={FileChartColumn}
          />
        </div>
      </div>
    </>

  );
}

// I-bind ang Layout sa pahinang ito
Dashboard.layout = (page) => <MainLayout>{page}</MainLayout>;