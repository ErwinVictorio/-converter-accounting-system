import React from 'react'
import MainLayout from "@/Layouts/MainLayout";
function ManageBrokers() {
  return (
    <div>ManageBrokers</div>
  )
}

ManageBrokers.layout = (page) => (
    <MainLayout title="Manage Brokers">{page}</MainLayout>
);

export default ManageBrokers

